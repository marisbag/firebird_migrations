<?

if (!PHP_SAPI === 'cli' || !empty($_SERVER['REMOTE_ADDR']))
    die('Only usable with cli');

$shortopts = '';
$longopts = array();

$longopts[] = 'db:';
$longopts[] = 'user:';
$longopts[] = 'password:';
$longopts[] = 'readStructure';
$longopts[] = 'applyStructure';
$longopts[] = 'output:';
$longopts[] = 'multiple';
$longopts[] = 'dir';

$options = getopt($shortopts, $longopts);

//to do add array matching checking
if(!isset($options['db'])) die("Missing argument db\n");
if(!isset($options['user'])) die("Missing argument user\n");
if(!isset($options['password'])) die("Missing argument password\n");

DatabaseConnection::init($options['db'], $options['user'], $options['password']);
if(DatabaseConnection::$is_connected) echo "\nConnected to database succesfuly\n";
$Tables = new Tables();
if(isset($options['readStructure'])) {
    $out = $Tables->loadList()->toJson();
    if(isset($options['multiple'])){
        foreach($out as $table) {
            file_put_contents(getcwd()."/".trim($table->TABLE_NAME).".json", json_encode($table, JSON_PRETTY_PRINT));    
        }
    } elseif($options['output'])
        file_put_contents(getcwd()."/".$options['output'], json_encode($out, JSON_PRETTY_PRINT));
}

if(isset($options['applyStructure'])) {
    $table_curr = $Tables->loadList()->toObject();
    if(!isset($options['dir'])) $options['dir'] = getcwd();
    $upd = new TableStructureUpdater($options['dir'], $table_curr);
}

DatabaseConnection::disconnect();

class DatabaseConnection {
    private static $db_handle;
    public static $is_connected = false;
    protected static $db_path = null;
    protected static $db_user = null;
    protected static $db_password = null;

    public static function init($path = false, $user = false, $password = false)
    {
        if($path) self::$db_path = $path;
        if($user) self::$db_user = $user;
        if($password) self::$db_password = $password;
        self::connect();
    }

    public static function disconnect() {
        if(self::$is_connected)
            ibase_close(self::$db_handle);
    }

    public static function connect() {
        if(!self::$is_connected && self::$db_path) {
            self::$db_handle = ibase_connect(self::$db_path, self::$db_user, self::$db_password, "UTF8") 
            or die ("Could not connect to database: <b>".self::$db_path."</b>");
            self::$is_connected = true;
        } elseif(!self::$is_connected && !self::$db_path) {
            die('Please provide valid database');
        } 
    }

    public static function getDbHandle() {
        if(!self::$is_connected) self::connect();
        return self::$db_handle;
    }

}

class TableStructureUpdater {
    private $runSql = array();
    private $db;
    private $tables_directory = null;
    private $db_struct = array();
    private $fs_struct = array();
    public function __construct($directory, $structure) {
        $this->db = DatabaseConnection::getDbHandle();
        $this->tables_directory = $directory;
        $this->db_struct = $structure;
        if(empty($this->db_struct)) die('Could not load db structure');
        $this->loadStructureFromFileSistem();
        $this->compareStructure();
    }

    private function loadStructureFromFileSistem() {
        $files = glob($this->tables_directory."/*.json");
        echo "\nFiles loaded :     of    ";
        $i=1;
        foreach($files as $file){
            $data = json_decode(file_get_contents($file));
            $this->fs_struct[$data->TABLE_NAME] = $data;
            echo "\033[10D".str_pad($i,3, " ", STR_PAD_LEFT)." of ".str_pad(count($files),3, " ", STR_PAD_LEFT);
        }   
    }

    private function compareStructure() {
        foreach($this->fs_struct as $table_name => $table) {
            echo "Checking table $table_name\n";
            if(!isset($this->db_struct[$table_name])){
                $this->createTable($table_name);
            } else {
                $this->compareFields($table_name);
            }
            $this->compareConstraints($table_name);    
            $this->compareIndexes($table_name);
        }
        file_put_contents(getcwd()."/update.sql", implode("\n", $this->runSql));    
    }

    private function createTable($table_name) {
        $sql = sprintf("CREATE TABLE %s (\n", $table_name);
        $fields_sql = array();
        foreach($this->fs_struct[$table_name]->FIELDS as $field) {
            $fsql = sprintf("\t %s %s", $field->NAME, $field->DATA_TYPE);
            if($field->DATA_TYPE=="VARCHAR") $fsql.=sprintf("(%s)", $field->LENGTH);
            $fields_sql[] = $fsql;
        }
        $sql .= implode(",\n", $fields_sql);
        $sql .= "); \nCOMMIT;\n";
        $this->runSql[] = $sql;
        $this->db_struct[$table_name] = new StdClass();
        $this->db_struct[$table_name] = $this->fs_struct[$table_name];
        $this->db_struct[$table_name]->INDEXES = array();
        $this->db_struct[$table_name]->CONSTRAINTS = array();
    }

    private function compareConstraints($table_name) {
        $fs_cn = $this->fs_struct[$table_name]->CONSTRAINTS;
        $db_cn = $this->db_struct[$table_name]->CONSTRAINTS;

        foreach($fs_cn as $constr) {
            if(!isset($db_cn[$constr->NAME])){
                if($constr->TYPE=="PRIMARY KEY") {
                    $this->runSql[] = sprintf('UPDATE RDB$RELATION_FIELDS SET RDB$NULL_FLAG = 1 WHERE RDB$FIELD_NAME = \'%s\' AND RDB$RELATION_NAME = \'%s\';', $constr->FIELD_NAME, $table_name);
                    $sql = sprintf("ALTER TABLE %s ADD CONSTRAINT %s primary key (%s);", $table_name, $constr->NAME, $constr->FIELD_NAME);
                } else {
                    $sql = sprintf("ALTER TABLE %s ADD CONSTRAINT %s foreign key (%s) references %s (%s) on update %s on delete %s;"
                    , $table_name, $constr->NAME, $constr->FIELD_NAME, $constr->REFERENCES_TABLE, $constr->REFERENCES_FIELD, $constr->ON_UPDATE,$constr->ON_DELETE);
                }
                $this->runSql[] = $sql;
            }
        }
    }

    private function compareIndexes($table_name) {
        $fs_in = $this->fs_struct[$table_name]->INDEXES;
        $db_in = $this->db_struct[$table_name]->INDEXES;

        foreach($fs_in as $index) {
            if(!isset($db_in[$index->NAME])){
                    $sql = sprintf("CREATE INDEX %s ON %s (%s);"
                    , $index->NAME, $table_name, implode(",",array_values($index->FIELD_NAMES)));
                $this->runSql[] = $sql;
            }
        }
    }

    private function compareFields($table_name) {
        $fs_fields = $this->fs_struct[$table_name]->FIELDS;
        $db_fields = $this->db_struct[$table_name]->FIELDS;
        foreach($fs_fields as $field) {
            if(!isset($db_fields[$field->NAME])) {
                $sql = sprintf("ALTER TABLE %s ADD %s %s", $table_name, $field->NAME, $field->DATA_TYPE);
                if($field->DATA_TYPE=="VARCHAR") $sql.=sprintf("(%s)", $field->LENGTH);
                $sql .= ";\n";
                $this->runSql[] = $sql;
            }
        }
    }
}



class Tables {
    private $db;
    private $tables;

    public function __construct() {
        $this->db = DatabaseConnection::getDbHandle();
    }

    public function loadList() {
        echo "Tables loaded :     of    ";
        $tables = array();
        $tmptables = array();
        $sql_tables = '
        SELECT DISTINCT RDB$RELATION_NAME as TABLE_NAME
        FROM RDB$RELATION_FIELDS
        WHERE RDB$SYSTEM_FLAG=0;';

        $q_tables = ibase_query($sql_tables);
        while($table = ibase_fetch_object($q_tables)) {
            $tmptables[$table->TABLE_NAME] = $table;
        }
        $i=0;
        foreach($tmptables as $table) {
            $i++;
            $tables[$table->TABLE_NAME] = new Table($this->db, $table->TABLE_NAME);
            echo "\033[10D".str_pad($i,3, " ", STR_PAD_LEFT)." of ".str_pad(count($tmptables),3, " ", STR_PAD_LEFT);
        }
        $this->tables = $tables;
        echo "\n";
        return $this;
    }

    public function toObject() {
        $data = array();
        foreach($this->tables as $table) {
            $data[trim($table->name)] = $table->getStructureObject(true);
        }
        return $data;
    }

    public function toJson() {
        $data = array();
        if(empty($this->tables)) return '{}'; 

        foreach($this->tables as $table) {
            $data[] = $table->getStructureObject();
        }
        return $data;
    }

}

class Table {

    private $db;
    public $name;
    public $constraints;
    public $indexes;
    public $fields;

    public function __construct($db, $table_name) {
        $this->db = $db;
        $this->name = $table_name;
        $this->loadFields();
        $this->loadConstaraints();
        $this->loadIndexes();
    }

    public function getStructureObject($use_keys = false) {
        $fields = array();
        $indexes = array();
        $constraints = array();
        if(empty($this->fields)) return new StdClass();

        foreach($this->fields as $field) {
            $fo = new StdClass();
            $fo->NAME = trim($field->FIELD_NAME);
            $fo->DATA_TYPE = trim($field->TYPE);
            if(trim($fo->DATA_TYPE)=='VARCHAR')
                $fo->LENGTH = $field->Length/4; //divided by 4 because utf8 internaly uses 4 bytes per character and length is returned in bytes
            if($use_keys)
            $fields[$fo->NAME] = $fo;
            else
            $fields[$fo->NAME] = $fo;
        }

        foreach($this->indexes as $index) {
            $io = new StdClass();
            $io->NAME = trim($index->INDEX_NAME);
            $io->FIELD_NAMES = explode(",",$index->FIELD_NAME);
            foreach($io->FIELD_NAMES as &$FIELD_NAME)
                $FIELD_NAME = trim($FIELD_NAME);
            $io->DESCRIPTION = trim($index->DESCRIPTION);
            if($use_keys)
                $indexes[$io->NAME] = $io;
            else
                $indexes[] = $io;
        }

        foreach($this->constraints as $constraint) {
            $co = new StdClass();
            $co->TYPE = trim($constraint->CONSTRAINT_TYPE);
            $co->NAME = trim($constraint->CONSTRAINT_NAME);
            $co->FIELD_NAME = trim($constraint->FIELD_NAME);
            $co->DESCRIPTION = trim($constraint->DESCRIPTION);
            if(trim($constraint->CONSTRAINT_TYPE)=='PRIMARY KEY') {
                if($use_keys)
                $constraints[$co->NAME] = $co;
                else
                $constraints[] = $co;
                continue;
            }
            $co->REFERENCES_TABLE = trim($constraint->REFERENCES_TABLE);
            $co->REFERENCES_FIELD = trim($constraint->REFERENCES_FIELD);            
            $co->ON_UPDATE = trim($constraint->ON_UPDATE);
            $co->ON_DELETE = trim($constraint->ON_DELETE);
            if($use_keys)
                $constraints[$co->NAME] = $co;
            else
                $constraints[] = $co;
        }
        $fullObj = new StdClass();
        $fullObj->TABLE_NAME = trim($this->name);
        $fullObj->FIELDS = $fields;
        $fullObj->INDEXES = $indexes;
        $fullObj->CONSTRAINTS = $constraints;
        return $fullObj;
    }

    private function loadIndexes() {
        $indexes = array();
        $sql_indexes = sprintf('
        SELECT RDB$INDEX_SEGMENTS.RDB$INDEX_NAME INDEX_NAME, list(RDB$INDEX_SEGMENTS.RDB$FIELD_NAME) AS field_name,
          RDB$INDICES.RDB$DESCRIPTION AS description
          --(RDB$INDEX_SEGMENTS.RDB$FIELD_POSITION + 1) AS field_position
        FROM RDB$INDEX_SEGMENTS
        LEFT JOIN RDB$INDICES ON RDB$INDICES.RDB$INDEX_NAME = RDB$INDEX_SEGMENTS.RDB$INDEX_NAME
        LEFT JOIN RDB$RELATION_CONSTRAINTS ON RDB$RELATION_CONSTRAINTS.RDB$INDEX_NAME = RDB$INDEX_SEGMENTS.RDB$INDEX_NAME
        WHERE UPPER(RDB$INDICES.RDB$RELATION_NAME)=\'%s\'         -- table name
        AND RDB$RELATION_CONSTRAINTS.RDB$CONSTRAINT_TYPE IS NULL
        GROUP BY RDB$INDICES.RDB$DESCRIPTION, RDB$INDEX_SEGMENTS.RDB$INDEX_NAME
        ', $this->name);
        $q_indexes = ibase_query($sql_indexes);
        while($index = ibase_fetch_object($q_indexes, IBASE_TEXT)) {
            $indexes[] = $index;
        }
        $this->indexes = $indexes;
    }

    private function loadConstaraints() {
        $constraints = array();
        $sql_constraints = sprintf('
        SELECT rc.RDB$CONSTRAINT_NAME CONSTRAINT_NAME,
          s.RDB$FIELD_NAME AS field_name,
          rc.RDB$CONSTRAINT_TYPE AS constraint_type,
          i.RDB$DESCRIPTION AS description,
          rc.RDB$DEFERRABLE AS is_deferrable,
          rc.RDB$INITIALLY_DEFERRED AS is_deferred,
          refc.RDB$UPDATE_RULE AS on_update,
          refc.RDB$DELETE_RULE AS on_delete,
          refc.RDB$MATCH_OPTION AS match_type,
          i2.RDB$RELATION_NAME AS references_table,
          s2.RDB$FIELD_NAME AS references_field,
          (s.RDB$FIELD_POSITION + 1) AS field_position
            FROM RDB$INDEX_SEGMENTS s
        LEFT JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = s.RDB$INDEX_NAME
        LEFT JOIN RDB$RELATION_CONSTRAINTS rc ON rc.RDB$INDEX_NAME = s.RDB$INDEX_NAME
        LEFT JOIN RDB$REF_CONSTRAINTS refc ON rc.RDB$CONSTRAINT_NAME = refc.RDB$CONSTRAINT_NAME
        LEFT JOIN RDB$RELATION_CONSTRAINTS rc2 ON rc2.RDB$CONSTRAINT_NAME = refc.RDB$CONST_NAME_UQ
        LEFT JOIN RDB$INDICES i2 ON i2.RDB$INDEX_NAME = rc2.RDB$INDEX_NAME
        LEFT JOIN RDB$INDEX_SEGMENTS s2 ON i2.RDB$INDEX_NAME = s2.RDB$INDEX_NAME
        WHERE i.RDB$RELATION_NAME=\'%s\'       -- table name
        AND rc.RDB$CONSTRAINT_TYPE IS NOT NULL
        ORDER BY rc.RDB$CONSTRAINT_TYPE desc
        ', $this->name);
        $q_constraints = ibase_query($sql_constraints);
        while($constraint = ibase_fetch_object($q_constraints)) {
            $constraints[] = $constraint;
        }
        $this->constraints = $constraints;
    }

    private function loadFields() {
        $fields = array();
        $sql_fields = sprintf('SELECT r.RDB$FIELD_NAME AS "FIELD_NAME", 
                    CASE f.RDB$FIELD_TYPE
                        WHEN 261 THEN \'BLOB sub_type \'||f.RDB$FIELD_SUB_TYPE
                        WHEN 14 THEN \'CHAR\'
                        WHEN 40 THEN \'CSTRING\'
                        WHEN 11 THEN \'D_FLOAT\'
                        WHEN 27 THEN \'DOUBLE PRECISION\'
                        WHEN 10 THEN \'FLOAT\'
                        WHEN 16 THEN \'INTEGER\'
                        WHEN 8 THEN \'INTEGER\'
                        WHEN 9 THEN \'QUAD\'
                        WHEN 7 THEN \'SMALLINT\'
                        WHEN 12 THEN \'DATE\'
                        WHEN 13 THEN \'TIME\'
                        WHEN 35 THEN \'TIMESTAMP\'
                        WHEN 37 THEN \'VARCHAR\'
                        ELSE \'UNKNOWN\'
                    END AS "TYPE",
                    f.RDB$FIELD_TYPE AS "DataType", 
                    typ.RDB$TYPE_NAME AS "TypeName", 
                    CASE WHEN typ.RDB$TYPE_NAME = \'BLOB\' THEN f.RDB$FIELD_SUB_TYPE ELSE \'\' END AS "SubType", 
                    CASE WHEN typ.RDB$TYPE_NAME = \'BLOB\' THEN sub.RDB$TYPE_NAME ELSE \'\' END AS "SubTypeName", 
                    f.RDB$FIELD_LENGTH AS "Length", 
                    f.RDB$FIELD_PRECISION AS "Precision", 
                    f.RDB$FIELD_SCALE AS "Scale", 
                    MIN(rc.RDB$CONSTRAINT_TYPE) AS "Constraint", 
                    MIN(i.RDB$INDEX_NAME) AS "Idx", 
                    CASE WHEN r.RDB$NULL_FLAG = 1 THEN \'NO\' ELSE \'YES\' END AS "Null", 
                    r.RDB$DEFAULT_VALUE AS "Default", 
                    r.RDB$FIELD_POSITION AS "Pos" 
            FROM RDB$RELATION_FIELDS r 
            LEFT JOIN RDB$FIELDS f ON r.RDB$FIELD_SOURCE = f.RDB$FIELD_NAME 
            LEFT JOIN RDB$INDEX_SEGMENTS s ON s.RDB$FIELD_NAME=r.RDB$FIELD_NAME 
            LEFT JOIN RDB$INDICES i ON i.RDB$INDEX_NAME = s.RDB$INDEX_NAME 
                AND i.RDB$RELATION_NAME = r.RDB$RELATION_NAME 
            LEFT JOIN RDB$RELATION_CONSTRAINTS rc ON rc.RDB$INDEX_NAME = s.RDB$INDEX_NAME 
                AND rc.RDB$INDEX_NAME = i.RDB$INDEX_NAME AND rc.RDB$RELATION_NAME = i.RDB$RELATION_NAME 
            LEFT JOIN RDB$REF_CONSTRAINTS REFC ON rc.RDB$CONSTRAINT_NAME = refc.RDB$CONSTRAINT_NAME 
            LEFT JOIN RDB$TYPES typ ON typ.RDB$FIELD_NAME = \'RDB$FIELD_TYPE\' 
                AND typ.RDB$TYPE = f.RDB$FIELD_TYPE 
            LEFT JOIN RDB$TYPES sub ON sub.RDB$FIELD_NAME = \'RDB$FIELD_SUB_TYPE\' 
                AND sub.RDB$TYPE = f.RDB$FIELD_SUB_TYPE 
            WHERE r.RDB$RELATION_NAME=\'%s\' 
            GROUP BY "FIELD_NAME", 
                    "TYPE",
                    "DataType", 
                    "TypeName", 
                    "SubType", 
                    "SubTypeName", 
                    "Length", 
                    "Precision", 
                    "Scale", 
                    "Null", 
                    "Default", 
                    "Pos" 
            ORDER BY "Pos"', $this->name);

        $q_fields = ibase_query($sql_fields);
        while($field = ibase_fetch_object($q_fields)) {
            $fields[] = $field;
        }
        $this->fields = $fields;
    }

}

?>
