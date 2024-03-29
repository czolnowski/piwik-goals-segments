<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

namespace Piwik;

use Closure;
use Exception;
use Piwik\Common;
use Piwik\DataTable\Manager;
use Piwik\DataTable\Renderer\Html;
use Piwik\DataTable\Row;
use Piwik\DataTable\Filter;
use Piwik\DataTable\Row\DataTableSummaryRow;
use ReflectionClass;

/**
 * @see Common::destroy()
 */
require_once PIWIK_INCLUDE_PATH . '/core/Common.php';

/**
 *
 * ---- DataTable
 * A DataTable is a data structure used to store complex tables of data.
 *
 * A DataTable is composed of multiple DataTable\Row.
 * A DataTable can be applied one or several DataTable_Filter.
 * A DataTable can be given to a DataTable_Renderer that would export the data under a given format (XML, HTML, etc.).
 *
 * A DataTable has the following features:
 * - serializable to be stored in the DB
 * - loadable from the serialized version
 * - efficient way of loading data from an external source (from a PHP array structure)
 * - very simple interface to get data from the table
 *
 * ---- DataTable\Row
 * A DataTableRow in the table is defined by
 * - multiple columns (a label, multiple values, ...)
 * - optional metadata
 * - optional - a sub DataTable associated to this row
 *
 * Simple row example:
 * - columns = array(   'label' => 'Firefox',
 *                        'visitors' => 155,
 *                        'pages' => 214,
 *                        'bounce_rate' => 67)
 * - metadata = array('logo' => '/plugins/UserSettings/images/browsers/FF.gif')
 * - no sub DataTable
 *
 * A more complex example would be a DataTable\Row that is associated to a sub DataTable.
 * For example, for the row of the search engine Google,
 * we want to get the list of keywords associated, with their statistics.
 * - columns = array(   'label' => 'Google',
 *                        'visits' => 1550,
 *                        'visits_length' => 514214,
 *                        'returning_visits' => 77)
 * - metadata = array(    'logo' => '/plugins/Referers/images/searchEngines/google.com.png',
 *                        'url' => 'http://google.com')
 * - DataTable = DataTable containing several DataTable\Row containing the keywords information for this search engine
 *            Example of one DataTable\Row
 *            - the keyword columns specific to this search engine =
 *                    array(  'label' => 'Piwik', // the keyword
 *                            'visitors' => 155,  // Piwik has been searched on Google by 155 visitors
 *                            'pages' => 214 // Visitors coming from Google with the kwd Piwik have seen 214 pages
 *                    )
 *            - the keyword metadata = array() // nothing here, but we could imagining storing the URL of the search in Google for example
 *            - no subTable
 *
 *
 * ---- DataTable_Filter
 * A DataTable_Filter is a applied to a DataTable and so
 * can filter information in the multiple DataTable\Row.
 *
 * For example a DataTable_Filter can:
 * - remove rows from the table,
 *        for example the rows' labels that do not match a given searched pattern
 *        for example the rows' values that are less than a given percentage (low population)
 * - return a subset of the DataTable
 *        for example a function that apply a limit: $offset, $limit
 * - add / remove columns
 *        for example adding a column that gives the percentage of a given value
 * - add some metadata
 *        for example the 'logo' path if the filter detects the logo
 * - edit the value, the label
 * - change the rows order
 *        for example if we want to sort by Label alphabetical order, or by any column value
 *
 * When several DataTable_Filter are to be applied to a DataTable they are applied sequentially.
 * A DataTable_Filter is assigned a priority.
 * For example, filters that
 *    - sort rows should be applied with the highest priority
 *    - remove rows should be applied with a high priority as they prune the data and improve performance.
 *
 * ---- Code example
 *
 * $table = new DataTable();
 * $table->addRowsFromArray( array(...) );
 *
 * # sort the table by visits asc
 * $filter = new DataTable_Filter_Sort( $table, 'visits', 'asc');
 * $tableFiltered = $filter->getTableFiltered();
 *
 * # add a filter to select only the website with a label matching '*.com' (regular expression)
 * $filter = new DataTable_Filter_Pattern( $table, 'label', '*(.com)');
 * $tableFiltered = $filter->getTableFiltered();
 *
 * # keep the 20 elements from offset 15
 * $filter = new DataTable_Filter_Limit( $tableFiltered, 15, 20);
 * $tableFiltered = $filter->getTableFiltered();
 *
 * # add a column computing the percentage of visits
 * # params = table, column containing the value, new column name to add, number of total visits to use to compute the %
 * $filter = new DataTable_Filter_AddColumnPercentage( $tableFiltered, 'visits', 'visits_percentage', 2042);
 * $tableFiltered = $filter->getTableFiltered();
 *
 * # we get the table as XML
 * $xmlOutput = new DataTable_Exporter_Xml( $table );
 * $xmlOutput->setHeader( ... );
 * $xmlOutput->setColumnsToExport( array('visits', 'visits_percent', 'label') );
 * $XMLstring = $xmlOutput->getOutput();
 *
 *
 * ---- Other (ideas)
 * We can also imagine building a DataTable_Compare which would take N DataTable that have the same
 * structure and would compare them, by computing the percentages of differences, etc.
 *
 * For example
 * DataTable1 = [ keyword1, 1550 visits]
 *                [ keyword2, 154 visits ]
 * DataTable2 = [ keyword1, 1004 visits ]
 *                [ keyword3, 659 visits ]
 * DataTable_Compare = result of comparison of table1 with table2
 *                        [ keyword1, +154% ]
 *                        [ keyword2, +1000% ]
 *                        [ keyword3, -430% ]
 *
 * @package Piwik
 * @subpackage DataTable
 */
class DataTable
{
    /** Name for metadata that describes when a report was archived. */
    const ARCHIVED_DATE_METADATA_NAME = 'archived_date';
    const MAX_DEPTH_DEFAULT = 15;
    /** Name for metadata that describes which columns are empty and should not be shown. */
    const EMPTY_COLUMNS_METADATA_NAME = 'empty_column';

    /**
     * Maximum nesting level.
     */
    private static $maximumDepthLevelAllowed = self::MAX_DEPTH_DEFAULT;

    /**
     * Array of Row
     *
     * @var Row[]
     */
    protected $rows = array();

    /**
     * Id assigned to the DataTable, used to lookup the table using the DataTable_Manager
     *
     * @var int
     */
    protected $currentId;

    /**
     * Current depth level of this data table
     * 0 is the parent data table
     *
     * @var int
     */
    protected $depthLevel = 0;

    /**
     * This flag is set to false once we modify the table in a way that outdates the index
     *
     * @var bool
     */
    protected $indexNotUpToDate = true;

    /**
     * This flag sets the index to be rebuild whenever a new row is added,
     * as opposed to re-building the full index when getRowFromLabel is called.
     * This is to optimize and not rebuild the full Index in the case where we
     * add row, getRowFromLabel, addRow, getRowFromLabel thousands of times.
     *
     * @var bool
     */
    protected $rebuildIndexContinuously = false;

    /**
     * Column name of last time the table was sorted
     *
     * @var string
     */
    protected $tableSortedBy = false;

    /**
     * List of Filter queued to this table
     *
     * @var array
     */
    protected $queuedFilters = array();

    /**
     * We keep track of the number of rows before applying the LIMIT filter that deletes some rows
     *
     * @var int
     */
    protected $rowsCountBeforeLimitFilter = 0;

    /**
     * Defaults to false for performance reasons (most of the time we don't need recursive sorting so we save a looping over the dataTable)
     *
     * @var bool
     */
    protected $enableRecursiveSort = false;

    /**
     * When the table and all subtables are loaded, this flag will be set to true to ensure filters are applied to all subtables
     *
     * @var bool
     */
    protected $enableRecursiveFilters = false;

    /**
     * @var array
     */
    protected $rowsIndexByLabel = array();

    /**
     * @var \Piwik\DataTable\Row
     */
    protected $summaryRow = null;

    /**
     * Table metadata.
     *
     * @var array
     */
    public $metadata = array();

    /**
     * Maximum number of rows allowed in this datatable (including the summary row).
     * If adding more rows is attempted, the extra rows get summed to the summary row.
     *
     * @var int
     */
    protected $maximumAllowedRows = 0;

    /**
     * The operations that should be used when aggregating columns from multiple rows.
     * @see self::addDataTable() and DataTable\Row::sumRow()
     */
    protected $columnAggregationOperations = array();

    const ID_SUMMARY_ROW = -1;
    const LABEL_SUMMARY_ROW = -1;

    /**
     * Builds the DataTable, registers itself to the manager
     *
     */
    public function __construct()
    {
        $this->currentId = Manager::getInstance()->addTable($this);
    }

    /**
     * At destruction we free all memory
     */
    public function __destruct()
    {
        static $depth = 0;
        // destruct can be called several times
        if ($depth < self::$maximumDepthLevelAllowed
            && isset($this->rows)
        ) {
            $depth++;
            foreach ($this->getRows() as $row) {
                Common::destroy($row);
            }
            unset($this->rows);
            Manager::getInstance()->setTableDeleted($this->getId());
            $depth--;
        }
    }

    /**
     * Sort the dataTable rows using the php callback function
     *
     * @param string $functionCallback
     * @param string $columnSortedBy    The column name. Used to then ask the datatable what column are you sorted by
     */
    public function sort($functionCallback, $columnSortedBy)
    {
        $this->indexNotUpToDate = true;
        $this->tableSortedBy = $columnSortedBy;
        usort($this->rows, $functionCallback);

        if ($this->enableRecursiveSort === true) {
            foreach ($this->getRows() as $row) {
                if (($idSubtable = $row->getIdSubDataTable()) !== null) {
                    $table = Manager::getInstance()->getTable($idSubtable);
                    $table->enableRecursiveSort();
                    $table->sort($functionCallback, $columnSortedBy);
                }
            }
        }
    }

    /**
     * Returns the name of the column the tables is sorted by
     *
     * @return bool|string
     */
    public function getSortedByColumnName()
    {
        return $this->tableSortedBy;
    }

    /**
     * Enables the recursive sort. Means that when using $table->sort()
     * it will also sort all subtables using the same callback
     */
    public function enableRecursiveSort()
    {
        $this->enableRecursiveSort = true;
    }

    /**
     * Enables the recursive filter. Means that when using $table->filter()
     * it will also filter all subtables using the same callback
     */
    public function enableRecursiveFilters()
    {
        $this->enableRecursiveFilters = true;
    }

    /**
     * Returns the number of rows before we applied the limit filter
     *
     * @return int
     */
    public function getRowsCountBeforeLimitFilter()
    {
        $toReturn = $this->rowsCountBeforeLimitFilter;
        if ($toReturn == 0) {
            return $this->getRowsCount();
        }
        return $toReturn;
    }

    /**
     * Saves the current number of rows
     */
    public function setRowsCountBeforeLimitFilter()
    {
        $this->rowsCountBeforeLimitFilter = $this->getRowsCount();
    }

    /**
     * Apply a filter to this datatable
     *
     * @param string|Closure $className   Class name, eg. "Sort" or "Sort".
     *                                    If this variable is a closure, it will get executed immediately.
     * @param array $parameters  Array of parameters to the filter, eg. array('nb_visits', 'asc')
     */
    public function filter($className, $parameters = array())
    {
        if ($className instanceof \Closure) {
            array_unshift($parameters, $this);
            call_user_func_array($className, $parameters);
            return;
        }

        if (!class_exists($className, true)) {
            $className = 'Piwik\DataTable\Filter\\' . $className;
        }
        $reflectionObj = new ReflectionClass($className);

        // the first parameter of a filter is the DataTable
        // we add the current datatable as the parameter
        $parameters = array_merge(array($this), $parameters);

        $filter = $reflectionObj->newInstanceArgs($parameters);

        $filter->enableRecursive($this->enableRecursiveFilters);

        $filter->filter($this);
    }

    /**
     * Queue a DataTable_Filter that will be applied when applyQueuedFilters() is called.
     * (just before sending the datatable back to the browser (or API, etc.)
     *
     * @param string $className   The class name of the filter, eg. Limit
     * @param array $parameters  The parameters to give to the filter, eg. array( $offset, $limit) for the filter Limit
     */
    public function queueFilter($className, $parameters = array())
    {
        if (!is_array($parameters)) {
            $parameters = array($parameters);
        }
        $this->queuedFilters[] = array('className' => $className, 'parameters' => $parameters);
    }

    /**
     * Apply all filters that were previously queued to this table
     * @see queueFilter()
     */
    public function applyQueuedFilters()
    {
        foreach ($this->queuedFilters as $filter) {
            $this->filter($filter['className'], $filter['parameters']);
        }
        $this->queuedFilters = array();
    }

    /**
     * Adds a new DataTable to this DataTable
     * Go through all the rows of the new DataTable and applies the algorithm:
     * - if a row in $table doesnt exist in $this we add the new row to $this
     * - if a row exists in both $table and $this we sum the columns values into $this
     * - if a row in $this doesnt exist in $table we add in $this the row of $table without modification
     *
     * A common row to 2 DataTable is defined by the same label
     *
     * @example  tests/core/DataTable.test.php
     *
     * @param \Piwik\DataTable $tableToSum
     */
    public function addDataTable(DataTable $tableToSum)
    {
        foreach ($tableToSum->getRows() as $row) {
            $labelToLookFor = $row->getColumn('label');
            $rowFound = $this->getRowFromLabel($labelToLookFor);
            if ($rowFound === false) {
                if ($labelToLookFor === self::LABEL_SUMMARY_ROW) {
                    $this->addSummaryRow($row);
                } else {
                    $this->addRow($row);
                }
            } else {
                $rowFound->sumRow($row, $copyMeta = true, $this->columnAggregationOperations);

                // if the row to add has a subtable whereas the current row doesn't
                // we simply add it (cloning the subtable)
                // if the row has the subtable already
                // then we have to recursively sum the subtables
                if (($idSubTable = $row->getIdSubDataTable()) !== null) {
                    $subTable = Manager::getInstance()->getTable($idSubTable);
                    $subTable->setColumnAggregationOperations($this->columnAggregationOperations);
                    $rowFound->sumSubtable($subTable);
                }
            }
        }
    }

    /**
     * Returns the Row that has a column 'label' with the value $label
     *
     * @param string $label  Value of the column 'label' of the row to return
     * @return \Piwik\DataTable\Row|bool  The row if found, false otherwise
     */
    public function getRowFromLabel($label)
    {
        $rowId = $this->getRowIdFromLabel($label);
        if ($rowId instanceof Row) {
            return $rowId;
        }
        if (is_int($rowId) && isset($this->rows[$rowId])) {
            return $this->rows[$rowId];
        }
        if ($rowId == self::ID_SUMMARY_ROW
            && !empty($this->summaryRow)
        ) {
            return $this->summaryRow;
        }
        return false;
    }

    /**
     * Returns the row id for the givel label
     *
     * @param string $label  Value of the column 'label' of the row to return
     * @return int|Row
     */
    public function getRowIdFromLabel($label)
    {
        $this->rebuildIndexContinuously = true;
        if ($this->indexNotUpToDate) {
            $this->rebuildIndex();
        }

        if ($label === self::LABEL_SUMMARY_ROW
            && !is_null($this->summaryRow)
        ) {
            return $this->summaryRow;
        }

        $label = (string)$label;
        if (!isset($this->rowsIndexByLabel[$label])) {
            return false;
        }
        return $this->rowsIndexByLabel[$label];
    }

    /**
     * Get an empty table with the same properties as this one
     *
     * @param bool $keepFilters
     *
     * @return \Piwik\DataTable
     */
    public function getEmptyClone($keepFilters = true)
    {
        $clone = new DataTable;
        if ($keepFilters) {
            $clone->queuedFilters = $this->queuedFilters;
        }
        $clone->metadata = $this->metadata;
        return $clone;
    }

    /**
     * Rebuilds the index used to lookup a row by label
     */
    private function rebuildIndex()
    {
        foreach ($this->getRows() as $id => $row) {
            $label = $row->getColumn('label');
            if ($label !== false) {
                $this->rowsIndexByLabel[$label] = $id;
            }
        }
        $this->indexNotUpToDate = false;
    }

    /**
     * Returns the ith row in the array
     *
     * @param int $id
     * @return \Piwik\DataTable\Row or false if not found
     */
    public function getRowFromId($id)
    {
        if (!isset($this->rows[$id])) {
            if ($id == self::ID_SUMMARY_ROW
                && !is_null($this->summaryRow)
            ) {
                return $this->summaryRow;
            }
            return false;
        }
        return $this->rows[$id];
    }

    /**
     * Returns a row that has the subtable ID matching the parameter
     *
     * @param int $idSubTable
     * @return \Piwik\DataTable\Row|bool    false if not found
     */
    public function getRowFromIdSubDataTable($idSubTable)
    {
        $idSubTable = (int)$idSubTable;
        foreach ($this->rows as $row) {
            if ($row->getIdSubDataTable() === $idSubTable) {
                return $row;
            }
        }
        return false;
    }

    /**
     * Add a row to the table and rebuild the index if necessary
     *
     * @param \Piwik\DataTable\Row $row  to add at the end of the array
     * @return \Piwik\DataTable\Row
     */
    public function addRow(Row $row)
    {
        // if there is a upper limit on the number of allowed rows and the table is full,
        // add the new row to the summary row
        if ($this->maximumAllowedRows > 0
            && $this->getRowsCount() >= $this->maximumAllowedRows - 1
        ) {
            if ($this->summaryRow === null) // create the summary row if necessary
            {
                $this->addSummaryRow(new Row(array(
                                                                  Row::COLUMNS => $row->getColumns()
                                                             )));
                $this->summaryRow->setColumn('label', self::LABEL_SUMMARY_ROW);
            } else {
                $this->summaryRow->sumRow($row, $enableCopyMetadata = false, $this->columnAggregationOperations);
            }
            return $this->summaryRow;
        }

        $this->rows[] = $row;
        if (!$this->indexNotUpToDate
            && $this->rebuildIndexContinuously
        ) {
            $label = $row->getColumn('label');
            if ($label !== false) {
                $this->rowsIndexByLabel[$label] = count($this->rows) - 1;
            }
            $this->indexNotUpToDate = false;
        }
        return $row;
    }

    /**
     * Sets the summary row (a dataTable can have only one summary row)
     *
     * @param Row $row
     * @return Row Returns $row.
     */
    public function addSummaryRow(Row $row)
    {
        $this->summaryRow = $row;
        $this->rowsIndexByLabel[$row->getColumn('label')] = self::ID_SUMMARY_ROW;
        return $row;
    }

    /**
     * Returns the dataTable ID
     *
     * @return int
     */
    public function getId()
    {
        return $this->currentId;
    }

    /**
     * Adds a new row from a PHP array data structure
     *
     * @param array $row  eg. array(Row::COLUMNS => array( 'visits' => 13, 'test' => 'toto'),)
     */
    public function addRowFromArray($row)
    {
        $this->addRowsFromArray(array($row));
    }

    /**
     * Adds a new row a PHP array data structure
     *
     * @param array $row  eg. array('name' => 'google analytics', 'license' => 'commercial')
     */
    public function addRowFromSimpleArray($row)
    {
        $this->addRowsFromSimpleArray(array($row));
    }

    /**
     * Returns the array of Row
     *
     * @return Row[]
     */
    public function getRows()
    {
        if (is_null($this->summaryRow)) {
            return $this->rows;
        } else {
            return $this->rows + array(self::ID_SUMMARY_ROW => $this->summaryRow);
        }
    }

    /**
     * Returns the array containing all rows values for the requested column
     *
     * @param string $name
     * @return array
     */
    public function getColumn($name)
    {
        $columnValues = array();
        foreach ($this->getRows() as $row) {
            $columnValues[] = $row->getColumn($name);
        }
        return $columnValues;
    }

    /**
     * Returns  the array containing all rows values of all columns which name starts with $name
     *
     * @param $name
     * @return array
     */
    public function getColumnsStartingWith($name)
    {
        $columnValues = array();
        foreach ($this->getRows() as $row) {
            $columns = $row->getColumns();
            foreach ($columns as $column => $value) {
                if (strpos($column, $name) === 0) {
                    $columnValues[] = $row->getColumn($column);
                }
            }
        }
        return $columnValues;
    }

    /**
     * Returns the list of columns the rows in this datatable contain. This will return the
     * columns of the first row with data and assume they occur in every other row as well.
     *
     * @return array
     */
    public function getColumns()
    {
        $result = array();
        foreach ($this->getRows() as $row) {
            $columns = $row->getColumns();
            if (!empty($columns)) {
                $result = array_keys($columns);
                break;
            }
        }

        // make sure column names are not DB index values
        foreach ($result as &$column) {
            if (isset(Metrics::$mappingFromIdToName[$column])) {
                $column = Metrics::$mappingFromIdToName[$column];
            }
        }

        return $result;
    }

    /**
     * Returns an array containing the rows Metadata values
     *
     * @param string $name  Metadata column to return
     * @return array
     */
    public function getRowsMetadata($name)
    {
        $metadataValues = array();
        foreach ($this->getRows() as $row) {
            $metadataValues[] = $row->getMetadata($name);
        }
        return $metadataValues;
    }

    /**
     * Returns the number of rows in the table
     *
     * @return int
     */
    public function getRowsCount()
    {
        if (is_null($this->summaryRow)) {
            return count($this->rows);
        } else {
            return count($this->rows) + 1;
        }
    }

    /**
     * Returns the first row of the DataTable
     *
     * @return \Piwik\DataTable\Row
     */
    public function getFirstRow()
    {
        if (count($this->rows) == 0) {
            if (!is_null($this->summaryRow)) {
                return $this->summaryRow;
            }
            return false;
        }
        $row = array_slice($this->rows, 0, 1);
        return $row[0];
    }

    /**
     * Returns the last row of the DataTable
     *
     * @return Row
     */
    public function getLastRow()
    {
        if (!is_null($this->summaryRow)) {
            return $this->summaryRow;
        }

        if (count($this->rows) == 0) {
            return false;
        }
        $row = array_slice($this->rows, -1);
        return $row[0];
    }

    /**
     * Returns the sum of the number of rows of all the subtables
     *        + the number of rows in the parent table
     *
     * @return int
     */
    public function getRowsCountRecursive()
    {
        $totalCount = 0;
        foreach ($this->rows as $row) {
            if (($idSubTable = $row->getIdSubDataTable()) !== null) {
                $subTable = Manager::getInstance()->getTable($idSubTable);
                $count = $subTable->getRowsCountRecursive();
                $totalCount += $count;
            }
        }

        $totalCount += $this->getRowsCount();
        return $totalCount;
    }

    /**
     * Delete a given column $name in all the rows
     *
     * @param string $name
     */
    public function deleteColumn($name)
    {
        $this->deleteColumns(array($name));
    }

    public function __sleep()
    {
        return array('rows', 'summaryRow');
    }

    /**
     * Rename a column in all rows
     *
     * @param string $oldName  Old column name
     * @param string $newName  New column name
     */
    public function renameColumn($oldName, $newName)
    {
        foreach ($this->getRows() as $row) {
            $row->renameColumn($oldName, $newName);
            if (($idSubDataTable = $row->getIdSubDataTable()) !== null) {
                Manager::getInstance()->getTable($idSubDataTable)->renameColumn($oldName, $newName);
            }
        }
        if (!is_null($this->summaryRow)) {
            $this->summaryRow->renameColumn($oldName, $newName);
        }
    }

    /**
     * Delete columns by name in all rows
     *
     * @param array $names
     * @param bool $deleteRecursiveInSubtables
     */
    public function deleteColumns($names, $deleteRecursiveInSubtables = false)
    {
        foreach ($this->getRows() as $row) {
            foreach ($names as $name) {
                $row->deleteColumn($name);
            }
            if (($idSubDataTable = $row->getIdSubDataTable()) !== null) {
                Manager::getInstance()->getTable($idSubDataTable)->deleteColumns($names, $deleteRecursiveInSubtables);
            }
        }
        if (!is_null($this->summaryRow)) {
            foreach ($names as $name) {
                $this->summaryRow->deleteColumn($name);
            }
        }
    }

    /**
     * Deletes the ith row
     *
     * @param int $id
     *
     * @throws Exception if the row $id cannot be found
     * @return void
     */
    public function deleteRow($id)
    {
        if ($id === self::ID_SUMMARY_ROW) {
            $this->summaryRow = null;
            return;
        }
        if (!isset($this->rows[$id])) {
            throw new Exception("Trying to delete unknown row with idkey = $id");
        }
        unset($this->rows[$id]);
    }

    /**
     * Deletes all row from offset, offset + limit.
     * If limit is null then limit = $table->getRowsCount()
     *
     * @param int $offset
     * @param int $limit
     * @return int
     */
    public function deleteRowsOffset($offset, $limit = null)
    {
        if ($limit === 0) {
            return 0;
        }

        $count = $this->getRowsCount();
        if ($offset >= $count) {
            return 0;
        }

        // if we delete until the end, we delete the summary row as well
        if (is_null($limit)
            || $limit >= $count
        ) {
            $this->summaryRow = null;
        }

        if (is_null($limit)) {
            $spliced = array_splice($this->rows, $offset);
        } else {
            $spliced = array_splice($this->rows, $offset, $limit);
        }
        $countDeleted = count($spliced);
        return $countDeleted;
    }

    /**
     * Deletes the rows from the list of rows ID
     *
     * @param array $aKeys  ID of the rows to delete
     * @throws Exception if any of the row to delete couldn't be found
     */
    public function deleteRows(array $aKeys)
    {
        foreach ($aKeys as $key) {
            $this->deleteRow($key);
        }
    }

    /**
     * Returns a simple output of the DataTable for easy visualization
     * Example: echo $datatable;
     *
     * @return string
     */
    public function __toString()
    {
        $renderer = new Html();
        $renderer->setTable($this);
        return (string)$renderer;
    }

    /**
     * Returns true if both DataTable are exactly the same.
     * Used in unit tests.
     *
     * @param \Piwik\DataTable $table1
     * @param \Piwik\DataTable $table2
     * @return bool
     */
    public static function isEqual(DataTable $table1, DataTable $table2)
    {
        $rows1 = $table1->getRows();
        $rows2 = $table2->getRows();

        $table1->rebuildIndex();
        $table2->rebuildIndex();

        if ($table1->getRowsCount() != $table2->getRowsCount()) {
            return false;
        }

        foreach ($rows1 as $row1) {
            $row2 = $table2->getRowFromLabel($row1->getColumn('label'));
            if ($row2 === false
                || !Row::isEqual($row1, $row2)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The serialization returns a one dimension array containing all the
     * serialized DataTable contained in this DataTable.
     * We save DataTable in serialized format in the Database.
     * Each row of this returned PHP array will be a row in the DB table.
     * At the end of the method execution, the dataTable may be truncated (if $maximum* parameters are set).
     *
     * The keys of the array are very important as they are used to define the DataTable
     *
     * IMPORTANT: The main table (level 0, parent of all tables) will always be indexed by 0
     *    even it was created after some other tables.
     *    It also means that all the parent tables (level 0) will be indexed with 0 in their respective
     *  serialized arrays. You should never lookup a parent table using the getTable( $id = 0) as it
     *  won't work.
     *
     * @throws Exception if an infinite recursion is found (a table row's has a subtable that is one of its parent table)
     * @param int $maximumRowsInDataTable          If not null, defines the number of rows maximum of the serialized dataTable
     * @param int $maximumRowsInSubDataTable       If not null, defines the number of rows maximum of the serialized subDataTable
     * @param string $columnToSortByBeforeTruncation  Column to sort by before truncation
     * @return array  Serialized arrays
     *            array(    // Datatable level0
     *                    0 => 'eghuighahgaueytae78yaet7yaetae',
     *
     *                    // first Datatable level1
     *                    1 => 'gaegae gh gwrh guiwh uigwhuige',
     *
     *                    //second Datatable level1
     *                    2 => 'gqegJHUIGHEQjkgneqjgnqeugUGEQHGUHQE',
     *
     *                    //first Datatable level3 (child of second Datatable level1 for example)
     *                    3 => 'eghuighahgaueytae78yaet7yaetaeGRQWUBGUIQGH&QE',
     *                    );
     */
    public function getSerialized($maximumRowsInDataTable = null,
                                  $maximumRowsInSubDataTable = null,
                                  $columnToSortByBeforeTruncation = null)
    {
        static $depth = 0;

        if ($depth > self::$maximumDepthLevelAllowed) {
            $depth = 0;
            throw new Exception("Maximum recursion level of " . self::$maximumDepthLevelAllowed . " reached. Maybe you have set a DataTable\Row with an associated DataTable belonging already to one of its parent tables?");
        }
        if (!is_null($maximumRowsInDataTable)) {
            $this->filter('AddSummaryRow',
                array($maximumRowsInDataTable - 1,
                      DataTable::LABEL_SUMMARY_ROW,
                      $columnToSortByBeforeTruncation)
            );
        }

        // For each row, get the serialized row
        // If it is associated to a sub table, get the serialized table recursively ;
        // but returns all serialized tables and subtable in an array of 1 dimension
        $aSerializedDataTable = array();
        foreach ($this->rows as $row) {
            if (($idSubTable = $row->getIdSubDataTable()) !== null) {
                $subTable = Manager::getInstance()->getTable($idSubTable);
                $depth++;
                $aSerializedDataTable = $aSerializedDataTable + $subTable->getSerialized($maximumRowsInSubDataTable, $maximumRowsInSubDataTable, $columnToSortByBeforeTruncation);
                $depth--;
            }
        }
        // we load the current Id of the DataTable
        $forcedId = $this->getId();

        // if the datatable is the parent we force the Id at 0 (this is part of the specification)
        if ($depth == 0) {
            $forcedId = 0;
        }

        // we then serialize the rows and store them in the serialized dataTable
        $addToRows = array(self::ID_SUMMARY_ROW => $this->summaryRow);

        $aSerializedDataTable[$forcedId] = serialize($this->rows + $addToRows);
        foreach ($this->rows as &$row) {
            $row->cleanPostSerialize();
        }

        return $aSerializedDataTable;
    }

    /**
     * Load a serialized string of a datatable.
     *
     * Does not load recursively all the sub DataTable.
     * They will be loaded only when requesting them specifically.
     *
     * The function creates all the necessary DataTable\Row
     *
     * @param string $stringSerialized  string of serialized datatable
     * @throws Exception
     */
    public function addRowsFromSerializedArray($stringSerialized)
    {
        $serialized = unserialize($stringSerialized);
        if ($serialized === false) {
            throw new Exception("The unserialization has failed!");
        }
        $this->addRowsFromArray($serialized);
    }

    /**
     * Loads the DataTable from a PHP array data structure
     *
     * @param array $array  Array with the following structure
     *                       array(
     *                             // row1
     *                             array(
     *                                   Row::COLUMNS => array( col1_name => value1, col2_name => value2, ...),
     *                                   Row::METADATA => array( metadata1_name => value1,  ...), // see Row
     *                             ),
     *                             // row2
     *                             array( ... ),
     *                       )
     */
    public function addRowsFromArray($array)
    {
        foreach ($array as $id => $row) {
            if (is_array($row)) {
                $row = new Row($row);
            }
            if ($id == self::ID_SUMMARY_ROW) {
                $this->summaryRow = $row;
            } else {
                $this->addRow($row);
            }
        }
    }

    /**
     * Loads the data from a simple php array.
     * Basically maps a simple multidimensional php array to a DataTable.
     * Not recursive (if a row contains a php array itself, it won't be loaded)
     *
     * @param array $array  Array with the simple structure:
     *                       array(
     *                             array( col1_name => valueA, col2_name => valueC, ...),
     *                             array( col1_name => valueB, col2_name => valueD, ...),
     *                       )
     * @throws Exception
     * @return void
     */
    public function addRowsFromSimpleArray($array)
    {
        if (count($array) === 0) {
            return;
        }

        // we define an exception we may throw if at one point we notice that we cannot handle the data structure
        $e = new Exception(" Data structure returned is not convertible in the requested format." .
            " Try to call this method with the parameters '&format=original&serialize=1'" .
            "; you will get the original php data structure serialized." .
            " The data structure looks like this: \n \$data = " . var_export($array, true) . "; ");

        // first pass to see if the array has the structure
        // array(col1_name => val1, col2_name => val2, etc.)
        // with val* that are never arrays (only strings/numbers/bool/etc.)
        // if we detect such a "simple" data structure we convert it to a row with the correct columns' names
        $thisIsNotThatSimple = false;

        foreach ($array as $columnValue) {
            if (is_array($columnValue) || is_object($columnValue)) {
                $thisIsNotThatSimple = true;
                break;
            }
        }
        if ($thisIsNotThatSimple === false) {
            // case when the array is indexed by the default numeric index
            if (array_keys($array) == array_keys(array_fill(0, count($array), true))) {
                foreach ($array as $row) {
                    $this->addRow(new Row(array(Row::COLUMNS => array($row))));
                }
            } else {
                $this->addRow(new Row(array(Row::COLUMNS => $array)));
            }
            // we have converted our simple array to one single row
            // => we exit the method as the job is now finished
            return;
        }

        foreach ($array as $key => $row) {
            // stuff that looks like a line
            if (is_array($row)) {
                /**
                 * We make sure we can convert this PHP array without losing information.
                 * We are able to convert only simple php array (no strings keys, no sub arrays, etc.)
                 *
                 */

                // if the key is a string it means that some information was contained in this key.
                // it cannot be lost during the conversion. Because we are not able to handle properly
                // this key, we throw an explicit exception.
                if (is_string($key)) {
                    throw $e;
                }
                // if any of the sub elements of row is an array we cannot handle this data structure...
                foreach ($row as $subRow) {
                    if (is_array($subRow)) {
                        throw $e;
                    }
                }
                $row = new Row(array(Row::COLUMNS => $row));
            } // other (string, numbers...) => we build a line from this value
            else {
                $row = new Row(array(Row::COLUMNS => array($key => $row)));
            }
            $this->addRow($row);
        }
    }

    /**
     * Rewrites the input $array
     * array (
     *     LABEL => array(col1 => X, col2 => Y),
     *     LABEL2 => array(col1 => X, col2 => Y),
     * )
     * to a DataTable, ie. with the internal structure
     * array (
     *     array( Row::COLUMNS => array('label' => LABEL, col1 => X, col2 => Y)),
     *     array( Row::COLUMNS => array('label' => LABEL2, col1 => X, col2 => Y)),
     * )
     *
     * It also works with array having only one value per row, eg.
     * array (
     *     LABEL => X,
     *     LABEL2 => Y,
     * )
     * would be converted to:
     * array (
     *     array( Row::COLUMNS => array('label' => LABEL, 'value' => X)),
     *     array( Row::COLUMNS => array('label' => LABEL2, 'value' => Y)),
     * )
     *
     *
     * @param array $array Indexed array, two formats are supported
     * @param array|null $subtablePerLabel An indexed array of up to one DataTable to associate as a sub table
     * @return \Piwik\DataTable
     */
    public static function makeFromIndexedArray($array, $subtablePerLabel = null)
    {
        $table = new DataTable();
        $cleanRow = array();
        foreach ($array as $label => $row) {
            // Support the case of an $array of single values
            if (!is_array($row)) {
                $row = array('value' => $row);
            }
            // Put the 'label' column first
            $cleanRow[Row::COLUMNS] = array('label' => $label) + $row;
            // Assign subtable if specified
            if (isset($subtablePerLabel[$label])) {
                $cleanRow[Row::DATATABLE_ASSOCIATED] = $subtablePerLabel[$label];
            }
            $table->addRow(new Row($cleanRow));
        }
        return $table;
    }

    /**
     * Sets the maximum nesting level to at least a certain value. If the current value is
     * greater than the supplied level, the maximum nesting level is not changed.
     *
     * @param int $atLeastLevel
     */
    public static function setMaximumDepthLevelAllowedAtLeast($atLeastLevel)
    {
        self::$maximumDepthLevelAllowed = max($atLeastLevel, self::$maximumDepthLevelAllowed);
        if (self::$maximumDepthLevelAllowed < 1) {
            self::$maximumDepthLevelAllowed = 1;
        }
    }

    /**
     * Returns all table metadata.
     *
     * @return array
     */
    public function getAllTableMetadata()
    {
        return $this->metadata;
    }

    /**
     * Returns metadata by name.
     *
     * @param string $name The metadata name.
     * @return mixed
     */
    public function getMetadata($name)
    {
        if (!isset($this->metadata[$name])) {
            return false;
        }
        return $this->metadata[$name];
    }

    /**
     * Sets a metadata value by name.
     *
     * @param string $name The metadata name.
     * @param mixed $value
     */
    public function setMetadata($name, $value)
    {
        $this->metadata[$name] = $value;
    }

    /**
     * Sets the maximum number of rows allowed in this datatable (including the summary
     * row). If adding more then the allowed number of rows is attempted, the extra
     * rows are added to the summary row.
     *
     * @param int|null $maximumAllowedRows
     */
    public function setMaximumAllowedRows($maximumAllowedRows)
    {
        $this->maximumAllowedRows = $maximumAllowedRows;
    }

    /**
     * Traverses a DataTable tree using an array of labels and returns the row
     * it finds or false if it cannot find one, and the number of segments of
     * the path successfully walked.
     * If $missingRowColumns is supplied, the specified path is created. When
     * a subtable is encountered w/o the queried label, a new row is created
     * with the label, and a subtable is added to the row.
     *
     * @param array $path            The path to walk. An array of label values.
     * @param array|bool $missingRowColumns
     *                                      The default columns to use when creating new arrays.
     *                                      If this parameter is supplied, new rows will be
     *                                      created if labels cannot be found.
     * @param int $maxSubtableRows The maximum number of allowed rows in new
     *                                      subtables.
     *
     * @return array First element is the found row or false. Second element is
     *               the number of path segments walked. If a row is found, this
     *               will be == to count($path). Otherwise, it will be the index
     *               of the path segment that we could not find.
     */
    public function walkPath($path, $missingRowColumns = false, $maxSubtableRows = 0)
    {
        $pathLength = count($path);

        $table = $this;
        $next = false;
        for ($i = 0; $i < $pathLength; ++$i) {
            $segment = $path[$i];

            $next = $table->getRowFromLabel($segment);
            if ($next === false) {
                // if there is no table to advance to, and we're not adding missing rows, return false
                if ($missingRowColumns === false) {
                    return array(false, $i);
                } else // if we're adding missing rows, add a new row
                {
                    $row = new DataTableSummaryRow();
                    $row->setColumns(array('label' => $segment) + $missingRowColumns);

                    $next = $table->addRow($row);

                    if ($next !== $row) // if the row wasn't added, the table is full
                    {
                        // Summary row, has no metadata
                        $next->deleteMetadata();
                        return array($next, $i);
                    }
                }
            }

            $table = $next->getSubtable();
            if ($table === false) {
                // if the row has no table (and thus no child rows), and we're not adding
                // missing rows, return false
                if ($missingRowColumns === false) {
                    return array(false, $i);
                } else if ($i != $pathLength - 1) // create subtable if missing, but only if not on the last segment
                {
                    $table = new DataTable();
                    $table->setMaximumAllowedRows($maxSubtableRows);
                    $table->setColumnAggregationOperations($this->columnAggregationOperations);
                    $next->setSubtable($table);
                    // Summary row, has no metadata
                    $next->deleteMetadata();
                }
            }
        }

        return array($next, $i);
    }

    /**
     * Returns a new DataTable that contains the rows of each of this table's subtables.
     *
     * @param string|bool $labelColumn       If supplied the label of the parent row will be
     *                                        added to a new column in each subtable row. If set to,
     *                                  'label' each subtable row's label will be prepended w/
     *                                        the parent row's label.
     * @param bool $useMetadataColumn If true and if $labelColumn is supplied, the parent row's
     *                                        label will be added as metadata.
     *
     * @return \Piwik\DataTable
     */
    public function mergeSubtables($labelColumn = false, $useMetadataColumn = false)
    {
        $result = new DataTable();
        foreach ($this->getRows() as $row) {
            $subtable = $row->getSubtable();
            if ($subtable !== false) {
                $parentLabel = $row->getColumn('label');

                // add a copy of each subtable row to the new datatable
                foreach ($subtable->getRows() as $id => $subRow) {
                    $copy = clone $subRow;

                    // if the summary row, add it to the existing summary row (or add a new one)
                    if ($id == self::ID_SUMMARY_ROW) {
                        $existing = $result->getRowFromId(self::ID_SUMMARY_ROW);
                        if ($existing === false) {
                            $result->addSummaryRow($copy);
                        } else {
                            $existing->sumRow($copy, $copyMeta = true, $this->columnAggregationOperations);
                        }
                    } else {
                        if ($labelColumn !== false) {
                            // if we're modifying the subtable's rows' label column, then we make
                            // sure to prepend the existing label w/ the parent row's label. otherwise
                            // we're just adding the parent row's label as a new column/metadata.
                            $newLabel = $parentLabel;
                            if ($labelColumn == 'label') {
                                $newLabel .= ' - ' . $copy->getColumn('label');
                            }

                            // modify the child row's label or add new column/metadata
                            if ($useMetadataColumn) {
                                $copy->setMetadata($labelColumn, $newLabel);
                            } else {
                                $copy->setColumn($labelColumn, $newLabel);
                            }
                        }

                        $result->addRow($copy);
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Returns a new DataTable created with data from a 'simple' array.
     *
     * @param array $array
     * @return \Piwik\DataTable
     */
    public static function makeFromSimpleArray($array)
    {
        $dataTable = new DataTable();
        $dataTable->addRowsFromSimpleArray($array);
        return $dataTable;
    }

    /**
     * Set the aggregation operation for a column, e.g. "min".
     * @see self::addDataTable() and DataTable\Row::sumRow()
     *
     * @param string $columnName
     * @param string $operation
     */
    public function setColumnAggregationOperation($columnName, $operation)
    {
        $this->columnAggregationOperations[$columnName] = $operation;
    }

    /**
     * Set multiple aggregation operations at once.
     * @param array $operations  format: column name => operation
     */
    public function setColumnAggregationOperations($operations)
    {
        foreach ($operations as $columnName => $operation) {
            $this->setColumnAggregationOperation($columnName, $operation);
        }
    }

    /**
     * Get the configured column aggregation operations
     */
    public function getColumnAggregationOperations()
    {
        return $this->columnAggregationOperations;
    }

    /**
     * Creates a new DataTable instance from a serialize()'d array of rows.
     *
     * @param string $data
     * @return \Piwik\DataTable
     */
    public static function fromSerializedArray($data)
    {
        $result = new DataTable();
        $result->addRowsFromSerializedArray($data);
        return $result;
    }
}
