<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\ORM\DataExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use Symbiote\MultiValueField\Fields\MultiValueDropdownField;
use Symbiote\MultiValueField\Fields\MultiValueTextField;
use SilverStripe\Forms\DropdownField;
use Symbiote\MultiValueField\Fields\KeyValueField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;

use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ViewableData;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\View\ArrayData;

use SilverStripe\Forms\FieldList;


use Psr\Log\LoggerInterface;

use Exception;


use ArrayObject;
use InvalidArgumentException;

/**
 * @author marcus
 */
class ElasticaSearch extends DataExtension
{
    private static $db = array(
        'QueryType' => 'Varchar',
        'SearchType' => 'MultiValueField', // types that a user can search within
        'SearchOnFields' => 'MultiValueField',
        'ExtraSearchFields' => 'MultiValueField',
        'BoostFields' => 'MultiValueField',
        'BoostMatchFields' => 'MultiValueField',
        // faceting fields
        'FacetFields' => 'MultiValueField',
        'CustomFacetFields' => 'MultiValueField',
        'FacetMapping' => 'MultiValueField',
        'FacetQueries' => 'MultiValueField',
        'MinFacetCount' => 'Int',
        // filter fields (not used for relevance, just for restricting data set)
        'FilterFields' => 'MultiValueField',
        // filters that users can explicitly choose from
        'UserFilters' => 'MultiValueField'
    );

    /**
     *
     * @var \Symbiote\ElasticSearcha\ExtensibleElasticService
     */
    public $searchService;

    /**
     * Current result set
     * @var ArrayList
     */
    protected $currentResults;

    /**
     * URL param for current search string
     *
     * @var string
     */
    public static $filter_param = 'filter';

    /**
     *
     * @var LoggerInterface
     */
    public $logger;


    public function getSelectableFields() {
        // only exists due to parent page type not implementing it, but failing with a custom engine
    }

    public function updateExtensibleSearchPageCMSFields(FieldList $fields)
    {
        $objFields = $this->owner->getSelectableFields();

        $types  = SiteTree::page_type_classes();
        $source = array_combine($types, $types);

        $extraSearchTypes = Config::inst()->get(ElasticaSearch::class, 'additional_search_types');
        ksort($source);
        $source           = is_array($extraSearchTypes) ? array_merge($source, $extraSearchTypes) : $source;
        $types            = MultiValueDropdownField::create('SearchType',
                _t('ExtensibleSearchPage.SEARCH_ITEM_TYPE', 'Search items of type'), $source);
        $fields->addFieldToTab('Root.Main', $types, 'Content');

        $fields->addFieldToTab('Root.Main',
            MultiValueDropdownField::create('SearchOnFields',
                _t('ExtensibleSearchPage.INCLUDE_FIELDS', 'Search On Fields'), $objFields), 'Content');
        $fields->addFieldToTab('Root.Main',
            MultiValueTextField::create('ExtraSearchFields', _t('ElasticSearch.EXTRA_FIELDS', 'Custom fields to search')),
            'Content');

        $this->addSortFields($fields, $objFields);
        $this->addBoostFields($fields, $objFields);
        $this->addFacetFields($fields, $objFields);



        $fields->removeByName('FacetMapping');
    }

    protected function addSortFields($fields, $objFields)
    {
        $sortFields = $objFields;
        unset($sortFields['Content']);
        unset($sortFields['Groups']);
        $fields->replaceField('SortBy',
            new DropdownField('SortBy', _t('ExtensibleSearchPage.SORT_BY', 'Sort By'), $sortFields));
    }

    protected function addFacetFields($fields, $objFields)
    {
        $fields->addFieldToTab(
            'Root.Main',
            $kv = new KeyValueField('FilterFields', _t('ExtensibleSearchPage.FILTER_FIELDS', 'Fields to filter by')),
            'Content'
        );

        $fields->addFieldToTab('Root.Main',
            new HeaderField('FacetHeader', _t('ExtensibleSearchPage.FACET_HEADER', 'Facet Settings')), 'Content');

        $fields->addFieldToTab(
            'Root.Main',
            new MultiValueDropdownField('FacetFields',
            _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields), 'Content'
        );

        $fields->addFieldToTab(
            'Root.Main',
            new MultiValueTextField('CustomFacetFields',
            _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')), 'Content'
        );

        $facetMappingFields = $objFields;
        if ($this->owner->CustomFacetFields && ($cff                = $this->owner->CustomFacetFields->getValues())) {
            foreach ($cff as $facetField) {
                $facetMappingFields[$facetField] = $facetField;
            }
        }
        $fields->addFieldToTab(
            'Root.Main',
            new KeyValueField('FacetMapping',
            _t('ExtensibleSearchPage.FACET_MAPPING', 'Mapping of facet title to nice title'), $facetMappingFields),
            'Content'
        );

        $fields->addFieldToTab(
            'Root.Main',
            new KeyValueField('FacetQueries',
            _t('ExtensibleSearchPage.FACET_QUERIES', 'Fields to create query facets for')), 'Content'
        );

        $fields->addFieldToTab('Root.Main',
            new NumericField('MinFacetCount',
            _t('ExtensibleSearchPage.MIN_FACET_COUNT', 'Minimum facet count for inclusion in facet results'), 2),
            'Content'
        );

        $fields->addFieldToTab(
            'Root.Main',
            $kv = KeyValueField::create('FacetFields',
                _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields), 'Content'
        );
        $kv->setRightTitle('FieldName in left column, display label in the right');

        $fields->addFieldToTab(
            'Root.Main',
            $kv = KeyValueField::create('UserFilters',
                _t('ExtensibleSearchPage.USER_FILTER_FIELDS', 'User selectable filters')), 'Content'
        );
        $kv->setRightTitle('Filter query on the left, label displayed on right');

        $fields->addFieldToTab(
            'Root.Main',
            $kv = KeyValueField::create('CustomFacetFields',
                _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')), 'Content'
        );
        $kv->setRightTitle('FieldName in left column, display label in the right');
    }

    protected function addBoostFields($fields, $objFields)
    {
        $boostVals = array();
        for ($i = 1; $i <= 10; $i++) {
            $boostVals[$i] = $i;
        }

        $fields->addFieldToTab(
            'Root.Main',
            new KeyValueField('BoostFields', _t('ExtensibleSearchPage.BOOST_FIELDS', 'Boost values'), $objFields,
            $boostVals), 'Content'
        );

        $fields->addFieldToTab(
            'Root.Main',
            $f = new KeyValueField('BoostMatchFields',
            _t('ExtensibleSearchPage.BOOST_MATCH_FIELDS', 'Boost fields with field/value matches'), array(), $boostVals),
            'Content'
        );
        $f->setRightTitle('Enter a field name, followed by the value to boost if found in the result set, eg "title:Home" ');
    }



    public function updateQueryBuilder($builder, $page)
    {
        // This is required to load the faceting/aggregation.
        $fieldFacets = $page->facetFieldMapping();
        if (count($fieldFacets)) {
            $builder->addFacetFields($fieldFacets);
        }

        if (isset($_GET['UserFilter'])) {
            $filters = $this->owner->UserFilters->getValues();
            if (count($filters)) {
                $queries = array_keys($filters);
                foreach ($_GET['UserFilter'] as $index => $junk) {
                    if (isset($queries[$index])) {
                        $builder->addFilter($queries[$index]);
                    }
                }
            }
        }
    }


    /**
     * Gets a list of facet based filters
     */
    public function getActiveFacets()
    {
        return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
    }

    /**
     * Retrieve all facets in the result set in a way that can be iterated
     * over conveniently.
     *
     * @return \ArrayList
     */
    public function AllFacets()
    {
        $engine = '';

        throw new \Exception("Please update ElasticaSearch::AllFacets");

        if (!$this->getResults()) {
            return new ArrayList(array());
        }
        $facets  = $this->getResults()->getFacets();
        $result  = array();
        $mapping = $this->facetFieldMapping();
        if (!is_array($facets)) {
            return ArrayList::create($result);
        }
        foreach ($facets as $title => $items) {
            $object        = new ViewableData();
            $object->Items = $this->currentFacets($title);
            $title         = isset($mapping[$title]) ? $mapping[$title] : $title;
            $object->Title = DBVarchar::create_field('Varchar', $title);
            $result[]      = $object;
        }
        return new ArrayList($result);
    }

    public function fieldsForFacets()
    {
        $fields      = Config::inst()->get(ElasticaSearch::class, 'facets');
        $facetFields = array('FacetFields', 'CustomFacetFields');
        if (!$fields) {
            $fields = array();
        }
        foreach ($facetFields as $name) {
            if ($this->owner->$name && $ff = $this->owner->$name->getValues()) {
                $types = $this->owner->searchableTypes('Page');
                foreach ($ff as $f) {
                    $fieldName = $this->searchService->getIndexFieldName($f, $types);
                    if (!$fieldName) {
                        $fieldName = $f;
                    }
                    $fields[] = $fieldName;
                }
            }
        }
        return $fields;
    }

    public function facetFieldMapping()
    {

        $selected = $this->owner->FacetFields->getValues();
        if (!$selected) {
            $selected = array();
        }
        $custom = $this->owner->CustomFacetFields->getValues();
        if (!$custom) {
            $custom = array();
        }

        $all = array_merge($selected, $custom);

        return $all;
    }

    /**
     * Get the list of facet values for the given term
     *
     * @param String $term
     */
    public function currentFacets($term = null)
    {
        if (!$this->getResults()) {
            return new ArrayList(array());
        }
        $facets        = $this->getResults()->getFacets();
        $queryFacets   = $this->owner->queryFacets();
        $me            = $this->owner;
        $convertFacets = function ($term, $raw) use ($facets, $queryFacets, $me) {
            $result = array();
            foreach ($raw as $facetTerm) {
                // if it's a query facet, then we may have a label for it
                if (isset($queryFacets[$facetTerm->Name])) {
                    $facetTerm->Name = $queryFacets[$facetTerm->Name];
                }
                $sq                          = $me->SearchQuery();
                $sep                         = strlen($sq) ? '&amp;' : '';
                $facetTerm->SearchLink       = $me->Link(self::RESULTS_ACTION).'?'.$sq.$sep.self::$filter_param."[$term][]=$facetTerm->Query";
                $facetTerm->QuotedSearchLink = $me->Link(self::RESULTS_ACTION).'?'.$sq.$sep.self::$filter_param."[$term][]=&quot;$facetTerm->Query&quot;";
                $result[]                    = new ArrayData($facetTerm);
            }
            return $result;
        };
        if ($term) {
            // return just that term
            $ret    = isset($facets[$term]) ? $facets[$term] : null;
            // lets update them all and add a link parameter
            $result = array();
            if ($ret) {
                $result = $convertFacets($term, $ret);
            }
            return new ArrayList($result);
        } else {
            $all = array();
            foreach ($facets as $term => $ret) {
                $result = $convertFacets($term, $ret);
                $all    = array_merge($all, $result);
            }
            return new ArrayList($all);
        }
        return new ArrayList($facets);
    }

    /**
     * Get the list of field -> query items to be used for faceting by query
     */
    public function queryFacets()
    {
        $fields = array();
        if ($this->owner->FacetQueries && $fq     = $this->owner->FacetQueries->getValues()) {
            $fields = array_flip($fq);
        }
        return $fields;
    }

    /**
     * Returns a url parameter string that was just used to execute the current query.
     *
     * This is useful for ensuring the parameters used in the search can be passed on again
     * for subsequent queries.
     *
     * @param array $exclusions
     * 			A list of elements that should be excluded from the final query string
     *
     * @return String
     */
    function SearchQuery()
    {
        $parts = parse_url($_SERVER['REQUEST_URI']);
        if (!$parts) {
            throw new InvalidArgumentException("Can't parse URL: ".$uri);
        }
        // Parse params and add new variable
        $params = array();
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            if (count($params)) {
                return http_build_query($params);
            }
        }
    }
}