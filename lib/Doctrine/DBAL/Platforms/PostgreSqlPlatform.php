<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;

/**
 * PostgreSqlPlatform.
 *
 * @since  2.0
 * @author Roman Borschel <roman@code-factory.org>
 * @author Lukas Smith <smith@pooteeweet.org> (PEAR MDB2 library)
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @todo   Rename: PostgreSQLPlatform
 */
class PostgreSqlPlatform extends AbstractPlatform
{
    /**
     * @var bool
     */
    private $useBooleanTrueFalseStrings = true;

    /**
     * PostgreSQL has different behavior with some drivers
     * with regard to how booleans have to be handled.
     *
     * Enables use of 'true'/'false' or otherwise 1 and 0 instead.
     *
     * @param bool $flag
     */
    public function setUseBooleanTrueFalseStrings($flag)
    {
        $this->useBooleanTrueFalseStrings = (bool)$flag;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubstringExpression($value, $from, $length = null)
    {
        if ($length === null) {
            return 'SUBSTRING(' . $value . ' FROM ' . $from . ')';
        }

        return 'SUBSTRING(' . $value . ' FROM ' . $from . ' FOR ' . $length . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function getNowExpression()
    {
        return 'LOCALTIMESTAMP(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getRegexpExpression()
    {
        return 'SIMILAR TO';
    }

    /**
     * {@inheritDoc}
     */
    public function getLocateExpression($str, $substr, $startPos = false)
    {
        if ($startPos !== false) {
            $str = $this->getSubstringExpression($str, $startPos);

            return 'CASE WHEN (POSITION('.$substr.' IN '.$str.') = 0) THEN 0 ELSE (POSITION('.$substr.' IN '.$str.') + '.($startPos-1).') END';
        }

        return 'POSITION('.$substr.' IN '.$str.')';
    }

    /**
     * {@inheritdoc}
     */
    protected function getDateArithmeticIntervalExpression($date, $operator, $interval, $unit)
    {
        if (self::DATE_INTERVAL_UNIT_QUARTER === $unit) {
            $interval *= 3;
            $unit = self::DATE_INTERVAL_UNIT_MONTH;
        }

        return "(" . $date ." " . $operator . " (" . $interval . " || ' " . $unit . "')::interval)";
    }

    /**
     * {@inheritDoc}
     */
    public function getDateDiffExpression($date1, $date2)
    {
        return '(DATE(' . $date1 . ')-DATE(' . $date2 . '))';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSequences()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsSchemas()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultSchemaName()
    {
        return 'public';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsIdentityColumns()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function usesSequenceEmulatedIdentityColumns()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentitySequenceName($tableName, $columnName)
    {
        return $tableName . '_' . $columnName . '_seq';
    }

    /**
     * {@inheritDoc}
     */
    public function supportsCommentOnStatement()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function prefersSequences()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function hasNativeGuidType()
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getListDatabasesSQL()
    {
        return 'SELECT datname FROM pg_database';
    }

    /**
     * {@inheritDoc}
     */
    public function getListSequencesSQL($database)
    {
        return "SELECT
                    c.relname, n.nspname AS schemaname
                FROM
                   pg_class c, pg_namespace n
                WHERE relkind = 'S' AND n.oid = c.relnamespace AND
                    (n.nspname NOT LIKE 'pg_%' AND n.nspname != 'information_schema')";
    }

    /**
     * {@inheritDoc}
     */
    public function getListTablesSQL()
    {
        return "SELECT quote_ident(tablename) AS table_name, schemaname AS schema_name
                FROM pg_tables WHERE schemaname NOT LIKE 'pg_%' AND schemaname != 'information_schema' AND tablename != 'geometry_columns' AND tablename != 'spatial_ref_sys'";
    }

    /**
     * {@inheritDoc}
     */
    public function getListViewsSQL($database)
    {
        return 'SELECT quote_ident(viewname) as viewname, schemaname, definition FROM pg_views';
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableForeignKeysSQL($table, $database = null)
    {
        return "SELECT quote_ident(r.conname) as conname, pg_catalog.pg_get_constraintdef(r.oid, true) as condef
                  FROM pg_catalog.pg_constraint r
                  WHERE r.conrelid =
                  (
                      SELECT c.oid
                      FROM pg_catalog.pg_class c, pg_catalog.pg_namespace n
                      WHERE " .$this->getTableWhereClause($table) ." AND n.oid = c.relnamespace
                  )
                  AND r.contype = 'f'";
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateViewSQL($name, $sql)
    {
        return 'CREATE VIEW ' . $name . ' AS ' . $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function getDropViewSQL($name)
    {
        return 'DROP VIEW '. $name;
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableConstraintsSQL($table)
    {
        $table = new Identifier($table);
        $table = $table->getName();

        return "SELECT
                    quote_ident(relname) as relname
                FROM
                    pg_class
                WHERE oid IN (
                    SELECT indexrelid
                    FROM pg_index, pg_class
                    WHERE pg_class.relname = '$table'
                        AND pg_class.oid = pg_index.indrelid
                        AND (indisunique = 't' OR indisprimary = 't')
                        )";
    }

    /**
     * {@inheritDoc}
     *
     * @license New BSD License
     * @link http://ezcomponents.org/docs/api/trunk/DatabaseSchema/ezcDbSchemaPgsqlReader.html
     */
    public function getListTableIndexesSQL($table, $currentDatabase = null)
    {
        return "SELECT quote_ident(relname) as relname, pg_index.indisunique, pg_index.indisprimary,
                       pg_index.indkey, pg_index.indrelid
                 FROM pg_class, pg_index
                 WHERE oid IN (
                    SELECT indexrelid
                    FROM pg_index si, pg_class sc, pg_namespace sn
                    WHERE " . $this->getTableWhereClause($table, 'sc', 'sn')." AND sc.oid=si.indrelid AND sc.relnamespace = sn.oid
                 ) AND pg_index.indexrelid = oid";
    }

    /**
     * @param string $table
     * @param string $classAlias
     * @param string $namespaceAlias
     *
     * @return string
     */
    private function getTableWhereClause($table, $classAlias = 'c', $namespaceAlias = 'n')
    {
        $whereClause = $namespaceAlias.".nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND ";
        if (strpos($table, ".") !== false) {
            list($schema, $table) = explode(".", $table);
            $schema = "'" . $schema . "'";
        } else {
            $schema = "ANY(string_to_array((select replace(replace(setting,'\"\$user\"',user),' ','') from pg_catalog.pg_settings where name = 'search_path'),','))";
        }

        $table = new Identifier($table);
        $whereClause .= "$classAlias.relname = '" . $table->getName() . "' AND $namespaceAlias.nspname = $schema";

        return $whereClause;
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableColumnsSQL($table, $database = null)
    {
        return "SELECT
                    a.attnum,
                    quote_ident(a.attname) AS field,
                    t.typname AS type,
                    format_type(a.atttypid, a.atttypmod) AS complete_type,
                    (SELECT t1.typname FROM pg_catalog.pg_type t1 WHERE t1.oid = t.typbasetype) AS domain_type,
                    (SELECT format_type(t2.typbasetype, t2.typtypmod) FROM
                      pg_catalog.pg_type t2 WHERE t2.typtype = 'd' AND t2.oid = a.atttypid) AS domain_complete_type,
                    a.attnotnull AS isnotnull,
                    (SELECT 't'
                     FROM pg_index
                     WHERE c.oid = pg_index.indrelid
                        AND pg_index.indkey[0] = a.attnum
                        AND pg_index.indisprimary = 't'
                    ) AS pri,
                    (SELECT pg_get_expr(adbin, adrelid)
                     FROM pg_attrdef
                     WHERE c.oid = pg_attrdef.adrelid
                        AND pg_attrdef.adnum=a.attnum
                    ) AS default,
                    (SELECT pg_description.description
                        FROM pg_description WHERE pg_description.objoid = c.oid AND a.attnum = pg_description.objsubid
                    ) AS comment
                    FROM pg_attribute a, pg_class c, pg_type t, pg_namespace n
                    WHERE ".$this->getTableWhereClause($table, 'c', 'n') ."
                        AND a.attnum > 0
                        AND a.attrelid = c.oid
                        AND a.atttypid = t.oid
                        AND n.oid = c.relnamespace
                    ORDER BY a.attnum";
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateDatabaseSQL($name)
    {
        return 'CREATE DATABASE ' . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function getAdvancedForeignKeyOptionsSQL(\Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKey)
    {
        $query = '';

        if ($foreignKey->hasOption('match')) {
            $query .= ' MATCH ' . $foreignKey->getOption('match');
        }

        $query .= parent::getAdvancedForeignKeyOptionsSQL($foreignKey);

        if ($foreignKey->hasOption('deferrable') && $foreignKey->getOption('deferrable') !== false) {
            $query .= ' DEFERRABLE';
        } else {
            $query .= ' NOT DEFERRABLE';
        }

        if (($foreignKey->hasOption('feferred') && $foreignKey->getOption('feferred') !== false)
            || ($foreignKey->hasOption('deferred') && $foreignKey->getOption('deferred') !== false)
        ) {
            $query .= ' INITIALLY DEFERRED';
        } else {
            $query .= ' INITIALLY IMMEDIATE';
        }

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff)
    {
        $sql = array();
        $commentsSQL = array();
        $columnSql = array();

        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $query = 'ADD ' . $this->getColumnDeclarationSQL($column->getQuotedName($this), $column->toArray());
            $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
            if ($comment = $this->getColumnComment($column)) {
                $commentsSQL[] = $this->getCommentOnColumnSQL($diff->name, $column->getName(), $comment);
            }
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $query = 'DROP ' . $column->getQuotedName($this);
            $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
        }

        foreach ($diff->changedColumns as $columnDiff) {
            /** @var $columnDiff \Doctrine\DBAL\Schema\ColumnDiff */
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            if ($this->isUnchangedBinaryColumn($columnDiff)) {
                continue;
            }

            $oldColumnName = $columnDiff->getOldColumnName()->getQuotedName($this);
            $column = $columnDiff->column;

            if ($columnDiff->hasChanged('type') || $columnDiff->hasChanged('precision') || $columnDiff->hasChanged('scale')) {
                $type = $column->getType();

                // here was a server version check before, but DBAL API does not support this anymore.
                $query = 'ALTER ' . $oldColumnName . ' TYPE ' . $type->getSqlDeclaration($column->toArray(), $this);
                $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
            }

            if ($columnDiff->hasChanged('default') || $columnDiff->hasChanged('type')) {
                $defaultClause = null === $column->getDefault()
                    ? ' DROP DEFAULT'
                    : ' SET' . $this->getDefaultValueDeclarationSQL($column->toArray());
                $query = 'ALTER ' . $oldColumnName . $defaultClause;
                $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
            }

            if ($columnDiff->hasChanged('notnull')) {
                $query = 'ALTER ' . $oldColumnName . ' ' . ($column->getNotNull() ? 'SET' : 'DROP') . ' NOT NULL';
                $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
            }

            if ($columnDiff->hasChanged('autoincrement')) {
                if ($column->getAutoincrement()) {
                    // add autoincrement
                    $seqName = $this->getIdentitySequenceName($diff->name, $oldColumnName);

                    $sql[] = "CREATE SEQUENCE " . $seqName;
                    $sql[] = "SELECT setval('" . $seqName . "', (SELECT MAX(" . $oldColumnName . ") FROM " . $diff->getName()->getQuotedName($this) . "))";
                    $query = "ALTER " . $oldColumnName . " SET DEFAULT nextval('" . $seqName . "')";
                    $sql[] = "ALTER TABLE " . $diff->getName()->getQuotedName($this) . " " . $query;
                } else {
                    // Drop autoincrement, but do NOT drop the sequence. It might be re-used by other tables or have
                    $query = "ALTER " . $oldColumnName . " " . "DROP DEFAULT";
                    $sql[] = "ALTER TABLE " . $diff->getName()->getQuotedName($this) . " " . $query;
                }
            }

            if ($columnDiff->hasChanged('comment')) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $diff->name,
                    $column->getName(),
                    $this->getColumnComment($column)
                );
            }

            if ($columnDiff->hasChanged('length')) {
                $query = 'ALTER ' . $column->getName() . ' TYPE ' . $column->getType()->getSqlDeclaration($column->toArray(), $this);
                $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' ' . $query;
            }
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) .
                ' RENAME COLUMN ' . $oldColumnName->getQuotedName($this) . ' TO ' . $column->getQuotedName($this);
        }

        $tableSql = array();

        if ( ! $this->onSchemaAlterTable($diff, $tableSql)) {
            if ($diff->newName !== false) {
                $sql[] = 'ALTER TABLE ' . $diff->getName()->getQuotedName($this) . ' RENAME TO ' . $diff->getNewName()->getQuotedName($this);
            }

            $sql = array_merge($this->getPreAlterTableIndexForeignKeySQL($diff), $sql, $this->getPostAlterTableIndexForeignKeySQL($diff), $commentsSQL);
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Checks whether a given column diff is a logically unchanged binary type column.
     *
     * Used to determine whether a column alteration for a binary type column can be skipped.
     * Doctrine's {@link \Doctrine\DBAL\Types\BinaryType} and {@link \Doctrine\DBAL\Types\BlobType}
     * are mapped to the same database column type on this platform as this platform
     * does not have a native VARBINARY/BINARY column type. Therefore the {@link \Doctrine\DBAL\Schema\Comparator}
     * might detect differences for binary type columns which do not have to be propagated
     * to database as there actually is no difference at database level.
     *
     * @param ColumnDiff $columnDiff The column diff to check against.
     *
     * @return boolean True if the given column diff is an unchanged binary type column, false otherwise.
     */
    private function isUnchangedBinaryColumn(ColumnDiff $columnDiff)
    {
        $columnType = $columnDiff->column->getType();

        if ( ! $columnType instanceof BinaryType && ! $columnType instanceof BlobType) {
            return false;
        }

        $fromColumn = $columnDiff->fromColumn instanceof Column ? $columnDiff->fromColumn : null;

        if ($fromColumn) {
            $fromColumnType = $fromColumn->getType();

            if ( ! $fromColumnType instanceof BinaryType && ! $fromColumnType instanceof BlobType) {
                return false;
            }

            return count(array_diff($columnDiff->changedProperties, array('type', 'length', 'fixed'))) === 0;
        }

        if ($columnDiff->hasChanged('type')) {
            return false;
        }

        return count(array_diff($columnDiff->changedProperties, array('length', 'fixed'))) === 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameIndexSQL($oldIndexName, Index $index, $tableName)
    {
        if (strpos($tableName, '.') !== false) {
            list($schema) = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return array('ALTER INDEX ' . $oldIndexName . ' RENAME TO ' . $index->getQuotedName($this));
    }

    /**
     * {@inheritdoc}
     */
    public function getCommentOnColumnSQL($tableName, $columnName, $comment)
    {
        $comment = $comment === null ? 'NULL' : "'$comment'";

        return "COMMENT ON COLUMN $tableName.$columnName IS $comment";
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateSequenceSQL(Sequence $sequence)
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this) .
               ' INCREMENT BY ' . $sequence->getAllocationSize() .
               ' MINVALUE ' . $sequence->getInitialValue() .
               ' START ' . $sequence->getInitialValue() .
               $this->getSequenceCacheSQL($sequence);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterSequenceSQL(Sequence $sequence)
    {
        return 'ALTER SEQUENCE ' . $sequence->getQuotedName($this) .
               ' INCREMENT BY ' . $sequence->getAllocationSize() .
               $this->getSequenceCacheSQL($sequence);
    }

    /**
     * Cache definition for sequences
     *
     * @param Sequence $sequence
     *
     * @return string
     */
    private function getSequenceCacheSQL(Sequence $sequence)
    {
        if ($sequence->getCache() > 1) {
            return ' CACHE ' . $sequence->getCache();
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getDropSequenceSQL($sequence)
    {
        if ($sequence instanceof Sequence) {
            $sequence = $sequence->getQuotedName($this);
        }
        return 'DROP SEQUENCE ' . $sequence . ' CASCADE';
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateSchemaSQL($schemaName)
    {
        return 'CREATE SCHEMA ' . $schemaName;
    }

    /**
     * {@inheritDoc}
     */
    public function schemaNeedsCreation($schemaName)
    {
        return !in_array($schemaName, array('default', 'public'));
    }

    /**
     * {@inheritDoc}
     */
    public function getDropForeignKeySQL($foreignKey, $table)
    {
        return $this->getDropConstraintSQL($foreignKey, $table);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL($tableName, array $columns, array $options = array())
    {
        $queryFields = $this->getColumnDeclarationListSQL($columns);

        if (isset($options['primary']) && ! empty($options['primary'])) {
            $keyColumns = array_unique(array_values($options['primary']));
            $queryFields .= ', PRIMARY KEY(' . implode(', ', $keyColumns) . ')';
        }

        $query = 'CREATE TABLE ' . $tableName . ' (' . $queryFields . ')';

        $sql[] = $query;

        if (isset($options['indexes']) && ! empty($options['indexes'])) {
            foreach ($options['indexes'] as $index) {
                $sql[] = $this->getCreateIndexSQL($index, $tableName);
            }
        }

        if (isset($options['foreignKeys'])) {
            foreach ((array) $options['foreignKeys'] as $definition) {
                $sql[] = $this->getCreateForeignKeySQL($definition, $tableName);
            }
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     *
     * Postgres wants boolean values converted to the strings 'true'/'false'.
     */
    public function convertBooleans($item)
    {
        if ( ! $this->useBooleanTrueFalseStrings) {
            return parent::convertBooleans($item);
        }

        if (is_array($item)) {
            foreach ($item as $key => $value) {
                if (is_bool($value) || is_numeric($item)) {
                    $item[$key] = ($value) ? 'true' : 'false';
                }
            }
        } else {
            if (is_bool($item) || is_numeric($item)) {
                $item = ($item) ? 'true' : 'false';
            }
        }

        return $item;
    }

    /**
     * {@inheritDoc}
     */
    public function convertBooleansToDbValue($item)
    {
        if ( ! $this->useBooleanTrueFalseStrings) {
            return parent::convertBooleansToDbValue($item);
        }

        if (is_array($item)) {
            foreach ($item as $key => $value) {
                if (is_bool($value) || is_numeric($value)) {
                    $item[$key] = $value ? 1 : 0;
                } elseif (is_string($value)) {
                    if (trim(strtolower($item)) === 'false') {
                        $item[$key] = 0;
                    } else {
                        $item[$key] = 1;
                    }
                }
            }
        } else {
            if (is_bool($item) || is_numeric($item)) {
                $item = $item ? 1 : 0;
            } elseif (is_string($item)) {
                if (trim(strtolower($item)) === 'false') {
                    $item = 0;
                } else {
                    $item = 1;
                }
            }
        }

        return $item;
    }
    
    /**
     * {@inheritDoc}
     */
    public function convertFromBoolean($item)
    {
        if ((null !== $item) && 
            (false !== $item) && 
            (true !== $item) && 
            in_array(strtolower($item), array('false', 'f', 'n', 'no', 'off'), true)
        ) {
            return false;
        } 
          
        return parent::convertFromBoolean($item);
    }

    /**
     * {@inheritDoc}
     */
    public function getSequenceNextValSQL($sequenceName)
    {
        return "SELECT NEXTVAL('" . $sequenceName . "')";
    }

    /**
     * {@inheritDoc}
     */
    public function getSetTransactionIsolationSQL($level)
    {
        return 'SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '
                . $this->_getTransactionIsolationLevelSQL($level);
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $field)
    {
        return 'BOOLEAN';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $field)
    {
        if ( ! empty($field['autoincrement'])) {
            return 'SERIAL';
        }

        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $field)
    {
        if ( ! empty($field['autoincrement'])) {
            return 'BIGSERIAL';
        }
        return 'BIGINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $field)
    {
        return 'SMALLINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getGuidTypeDeclarationSQL(array $field)
    {
        return 'UUID';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $fieldDeclaration)
    {
        return 'TIMESTAMP(0) WITHOUT TIME ZONE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzTypeDeclarationSQL(array $fieldDeclaration)
    {
        return 'TIMESTAMP(0) WITH TIME ZONE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $fieldDeclaration)
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $fieldDeclaration)
    {
        return 'TIME(0) WITHOUT TIME ZONE';
    }

    /**
     * {@inheritDoc}
     */
    public function getGuidExpression()
    {
        return 'UUID_GENERATE_V4()';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $columnDef)
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function getVarcharTypeDeclarationSQLSnippet($length, $fixed)
    {
        return $fixed ? ($length ? 'CHAR(' . $length . ')' : 'CHAR(255)')
                : ($length ? 'VARCHAR(' . $length . ')' : 'VARCHAR(255)');
    }

    /**
     * {@inheritdoc}
     */
    protected function getBinaryTypeDeclarationSQLSnippet($length, $fixed)
    {
        return 'BYTEA';
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $field)
    {
        return 'TEXT';
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'postgresql';
    }

    /**
     * {@inheritDoc}
     *
     * PostgreSQL returns all column names in SQL result sets in lowercase.
     */
    public function getSQLResultCasing($column)
    {
        return strtolower($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTzFormatString()
    {
        return 'Y-m-d H:i:sO';
    }

    /**
     * {@inheritDoc}
     */
    public function getEmptyIdentityInsertSQL($quotedTableName, $quotedIdentifierColumnName)
    {
        return 'INSERT INTO ' . $quotedTableName . ' (' . $quotedIdentifierColumnName . ') VALUES (DEFAULT)';
    }

    /**
     * {@inheritDoc}
     */
    public function getTruncateTableSQL($tableName, $cascade = false)
    {
        return 'TRUNCATE '.$tableName.' '.(($cascade)?'CASCADE':'');
    }

    /**
     * {@inheritDoc}
     */
    public function getReadLockSQL()
    {
        return 'FOR SHARE';
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeDoctrineTypeMappings()
    {
        $this->doctrineTypeMapping = array(
            'smallint'      => 'smallint',
            'int2'          => 'smallint',
            'serial'        => 'integer',
            'serial4'       => 'integer',
            'int'           => 'integer',
            'int4'          => 'integer',
            'integer'       => 'integer',
            'bigserial'     => 'bigint',
            'serial8'       => 'bigint',
            'bigint'        => 'bigint',
            'int8'          => 'bigint',
            'bool'          => 'boolean',
            'boolean'       => 'boolean',
            'text'          => 'text',
            'varchar'       => 'string',
            'interval'      => 'string',
            '_varchar'      => 'string',
            'char'          => 'string',
            'bpchar'        => 'string',
            'inet'          => 'string',
            'date'          => 'date',
            'datetime'      => 'datetime',
            'timestamp'     => 'datetime',
            'timestamptz'   => 'datetimetz',
            'time'          => 'time',
            'timetz'        => 'time',
            'float'         => 'float',
            'float4'        => 'float',
            'float8'        => 'float',
            'double'        => 'float',
            'double precision' => 'float',
            'real'          => 'float',
            'decimal'       => 'decimal',
            'money'         => 'decimal',
            'numeric'       => 'decimal',
            'year'          => 'date',
            'uuid'          => 'guid',
            'bytea'         => 'blob',
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getVarcharMaxLength()
    {
        return 65535;
    }

    /**
     * {@inheritdoc}
     */
    public function getBinaryMaxLength()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getBinaryDefaultLength()
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    protected function getReservedKeywordsClass()
    {
        return 'Doctrine\DBAL\Platforms\Keywords\PostgreSQLKeywords';
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $field)
    {
        return 'BYTEA';
    }
}
