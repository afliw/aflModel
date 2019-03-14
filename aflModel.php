<?php

class aflModel {
    private $columns, $dbName, $tableName, $tableType, $externalObjects, $bringReferences;

    private static $debugChannel;

    function __construct($data, $bringReferences){
        self::Debug("Initializing object with data: " . json_encode($data));
        $this->bringReferences = $bringReferences;
        $this->tableName = $this->tableName ? $this->tableName : $this->getTableName();
        $this->dbName = CFG_DB_DBNAME;
        $this->getTableProperties();
        $this->externalObjects = array();
        if($this->bringReferences) $this->getTableReferences();
        if(!is_null($data)) $this->loadFromDataArray($data);
    }

    public function __get($name){
        $returnColumn = $name[0] == "_";
        $parsedName = $returnColumn ? substr($name, 1, strlen($name)) : $name;
		foreach ($this->columns as $column) {
		    if($column->Name == $parsedName) return $returnColumn ? $column : $column->Value;
		}
        foreach ($this->columns as $column) {
            if($column->IsForeignKey && get_class($column->ForeignObject) == $parsedName)
                return $column->ForeignObject;
        }
		trigger_error("aflModel > Unkown property '$name' in object/table '$this->tableName'");
	}

    public function __set($name, $value){
        foreach ($this->columns as $column) {
		    if($column->Name == $name){
                if($column->Value === $value) return $value;
                if($column->AutoIncrement) {
                    trigger_error("aflModel\SetProperty > Cannot set value on property with autoincrement");
                    return;
                }
                $column->Value = $value; // IDEA: podría verificar basandose en $column->DataType
                $column->HasChanged = true;
                if($column->IsForeignKey && $column->ForeignObject && $this->bringReferences)
                    $column->ForeignObject->GetById($value);
                return $value;
            }
		}
        trigger_error("aflModel\SetProperty > Unkown property '$name' in object/table '$this->tableName'");
    }
    
    public static function SetDebugChannel(Callable $callback) {
        if(!is_callable($callback)) throw "aflModel::SetDebugChannel > Argument is not a function.";
        self::$debugChannel = $callback;
    }

    public static function Debug($msg) {
        if(!self::$debugChannel) return;
        $msg = date("Y-m-d h:i:s") . " > " . $msg . PHP_EOL;
        $cb = self::$debugChannel;
        $cb($msg);
    }

    private function getTableProperties(){
        $this->getTableColumns();
    }

    private function getTableColumns(){
        self::Debug("Getting columns for table '$this->dbName.$this->tableName'.");
        $res = Cache::Retrieve([$this->dbName, $this->tableName, "columns"]);
        if(!$res) {
            self::Debug("Schema not in cache, querying database.");
            $query = "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, EXTRA, COLUMN_KEY, TABLE_NAME, TABLE_SCHEMA
                  FROM information_schema.`COLUMNS`
				  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
            $res = SDB::EscRead($query, array($this->dbName, $this->tableName));
            Cache::Store([$this->dbName, $this->tableName, "columns"], $res);
        } 
		$this->columns = array();
		foreach ($res as $columnProperties){
            $tCol = new Column($columnProperties);
			array_push($this->columns, $tCol);
		}
    }

    protected function getTableReferences(){
        self::Debug("Retriving references for table '$this->dbName.$this->tableName'");
        $res = Cache::Retrieve([$this->dbName, $this->tableName, "references"]);
        if(!$res) {
            self::Debug("Schema not in cache, querying database.");
            $query = "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_TABLE_SCHEMA
                  FROM information_schema.`KEY_COLUMN_USAGE`
                  WHERE REFERENCED_TABLE_SCHEMA = ?
                  AND REFERENCED_TABLE_NAME = ?";
            $res = SDB::EscRead($query, array($this->dbName, $this->tableName));
            Cache::Store([$this->dbName, $this->tableName, "references"], $res);
        }
        
        foreach($res as $row){
            $this->createReferenceObject($row);
            foreach ($this->columns as $col) {
                if($col->NameInDb == $row["REFERENCED_COLUMN_NAME"]) $col->HasReferences = true;
            }
        }
    }

    protected function createReferenceObject($referenceData){
        array_push($this->externalObjects, new ExternalReference($referenceData));
    }

    protected function getTableName(){
        $tableName = explode("\\", get_class($this));
        $tableName = $tableName[count($tableName) - 1];
        self::Debug("Getting table properties for '" . CFG_DB_DBNAME . "." . $tableName ."'");
        $res = Cache::Retrieve([CFG_DB_DBNAME, $tableName]);
        if(!$res) {
            self::Debug("Schema not in cache, querying database.");
            $query = "SELECT TABLE_TYPE
				  FROM information_schema.`TABLES`
				  WHERE table_schema = ?
				  AND table_name = ?
				  LIMIT 1";
            $res = SDB::EscRead($query, array(CFG_DB_DBNAME, Util::CamelToSnakeCase($tableName)));
            Cache::Store([CFG_DB_DBNAME, $tableName], $res);
        }
		if($res && count($res) > 0){
            $this->tableType = $res[0]["TABLE_TYPE"];
            return Util::CamelToSnakeCase($tableName);
        }		
		else
			trigger_error("aflModEx\getTableName > Table '$tableName' not found on database.");
    }
    
    private function pkExists() {
        $primaryKeys = $this->getPKarray();
        $pkArray = array_map(function($pk){
            return "$pk = :$pk";
        }, $primaryKeys);
        $selectCondition = implode(" AND ", $pkArray);
        $query = "SELECT COUNT(*) as 'count' FROM $this->tableName WHERE $selectCondition";
        $params = array();
        foreach($this->columns as $column) {
            if(!$column->IsPrimaryKey) continue;
            $params[$column->NameInDb] = $column->Value;
        }
        $res = SDB::EscRead($query, $params);
        return $res[0]["count"] > 0;
    }

    public function GetById($id){
        self::Debug("Fetching '$this->dbName.$this->tableName' by id: '$id'");
        $primaryKeys = $this->getPKarray();
        if(count($primaryKeys) > 1 && (!is_array($id) || count($id) !== count($primaryKeys))){
            return trigger_error("aflModel\GetById > The number of elements provided in parameter on method is not the same as present primary keys on table. " .
                                 "In case of have multiple primary keys, an array should be passed as parameter on method");
        }
        $pkArray = array_map(function($pk){
            return "$pk = :$pk";
        }, $primaryKeys);
        $selectCondition = implode(" AND ", $pkArray);
        $queryColumns = implode(', ', $this->getColArray());
        $query = "SELECT $queryColumns FROM $this->tableName WHERE $selectCondition";
        $id = is_array($id) ? $id : array($primaryKeys[0] => $id);
        $res = SDB::EscRead($query, $id);
        if($res){
            $this->loadFromDb($res[0]);
            return true;
        }
        return false;
    }

    private function loadFromDb($data){
        self::Debug("Loading object with data from database.");
        foreach ($this->columns as $column) {
            if(array_key_exists($column->NameInDb, $data)){
                $column->Value = $data[$column->NameInDb];
                if($column->IsForeignKey && $column->ForeignObject) $column->ForeignObject->GetById($column->Value);
            }
        }
    }

    public function Save(){
        self::Debug("Saving object to database.");
        if($this->tableType !== "BASE TABLE"){
            trigger_error("aflModel\Save > Table type '$this->tableType' cannot be saved ");
            return false;
        }
        if(!$this->checkNullables()){
            trigger_error("aflModel\Save\Insert > Trying to insert or update an object with a null property in a non-nullable field on table '{$this->tableName}'");
            return false;
        }

        if($this->bringReferences){
            foreach ($this->columns as $column) {
                if($column->IsForeignKey && $column->ForeignObject)
                    $column->ForeignObject->Save();
            }
        }

        foreach ($this->columns as $column) {
            if(!$column->IsPrimaryKey) continue;
            if(is_null($column->Value) && $column->AutoIncrement) return $this->insertObject();
            if(!is_null($column->Value) && !$this->pkExists()) return $this->insertObject(); 
        }
        return $this->updateObject();
    }

    private function loadFromDataArray($data){
        self::Debug("Loading '$this->dbName.$this->tableName' object with data from array.");
        if(!Util::IsAssociativeArray($data)) trigger_error("aflModel\Load > Provided array is not an associative array");
        foreach ($data as $key => $value) {
            foreach ($this->columns as $column) {
                if($key === $column->Name || $key === $column->NameInDb){
                    $column->Value = $value;
                    $column->HasChanged = true;
                } 
                if($column->IsForeignKey && $column->ForeignObject && $this->bringReferences) $column->ForeignObject->GetById($value);            
            }
        }
        return true;
    }

    public function SetData($dataArray, $bringReferences = null){
        $this->bringReferences = $bringReferences !== null ? $bringReferences : $this->bringReferences;
        return $this->loadFromDataArray($dataArray);
    }

    public function ToArray($dbName = false, $foreignObjects = false){
        $assArr = array();
        foreach ($this->columns as $column) {
            $assArr[$dbName ? $column->NameInDb : $column->Name] = $column->Value;
            if($foreignObjects && $column->IsForeignKey){
                $propName = get_class($column->ForeignObject);
                $assArr[$propName] = $column->ForeignObject->GetAssociativeArray($dbName, true);
            }
        }
        return $assArr;
    }

    private function insertObject(){
        self::Debug("Inserting in table '$this->dbName.$this->tableName'");
        $pairArray = $this->getPairArray(true);
        $columnString = implode(", ", array_keys($pairArray));
        $valueString = implode(", ", array_map(function($k){
            return ":$k";
        }, array_keys($pairArray)));

        $query = "INSERT INTO $this->tableName ($columnString) VALUES ($valueString)";
        $res = SDB::EscWrite($query, $pairArray);
        if($res){
            foreach ($this->columns as $column) {
                if($column->AutoIncrement)
                    $column->Value = $res;
            }
        }
        return !!$res;
    }

    private function checkNullables(){
        foreach ($this->columns as $col) {
            if(!$col->IsNullable && $col->Value === null && (!$col->AutoIncrement || $col->HasChanged)) return false;
        }
        return true;
    }

    private function updateObject (){
        self::Debug("Updating in table '$this->dbName.$this->tableName'");
        if(!$this->hasChanges()) return true;
        $setString = implode(", ", array_map(function($column){
            return "$column->NameInDb = :$column->NameInDb";
        }, array_filter($this->columns, function($column){
            return !$column->IsPrimaryKey && $column->HasChanged;
        })));
        $conditionString = implode(" AND ", array_map(function($column){
            return "$column->NameInDb = :$column->NameInDb";
        }, array_filter($this->columns, function($column){
            return $column->IsPrimaryKey;
        })));
        $parameterArray = array();
        foreach ($this->columns as $column) {
            if($column->IsPrimaryKey || $column->HasChanged)
                $parameterArray[$column->NameInDb] = $column->Value;
        }
        $query = "UPDATE $this->tableName SET $setString WHERE $conditionString";
        return SDB::EscWrite($query, $parameterArray);
    }

    private function hasChanges(){
        foreach ($this->columns as $column) {
            if($column->HasChanged) return true;
        }
        return false;
    }

    private function getPKarray(){
        $primaryKeys = array();
        foreach ($this->columns as $column) {
            if($column->IsPrimaryKey)
                array_push($primaryKeys, $column->NameInDb);
        }
        return $primaryKeys;
    }

    private function getPairArray($noNull = false, $onlyChanged = false, $onlyPK = false){
        $pairArray = array();
        foreach ($this->columns as $column) {
            if( ($noNull && is_null($column->Value)) ||
                ($onlyChanged && !$column->HasChanged) ||
                ($onlyPK && !$column->IsPrimaryKey) )
                    continue;
            $pairArray[$column->NameInDb] = $column->Value;
        }
        return $pairArray;
    }

    private function getColArray(){
        return array_map(function($col){
            return $col->NameInDb;
        }, $this->columns);
    }

    public static function Create($tableName, $data = null, $bringReferences = false){
        self::Debug("Creating class '$tableName'. Data passed: " . json_encode($data) . ". Bring references: " . ($bringReferences ? "yes." : "no."));
        $tableName = Util::SnakeToCamelCase($tableName);
		if(!class_exists($tableName))
			eval("class $tableName extends aflModel {}");
		return new $tableName($data, $bringReferences);
    }
}

class Column {
	public $Name, $IsForeignKey, $HasReferences, $IsPrimaryKey, $Value, $HasChanged, $DataType, $IsNullable, $AutoIncrement, $NameInDb, $ForeignObject;

    function __construct($properties){
        $this->setProperties($properties);
        if($this->IsForeignKey)
            $this->buildForeignObject($properties["TABLE_NAME"], $properties["TABLE_SCHEMA"]);
    }

    private function setProperties($properties){
        $this->NameInDb = $properties["COLUMN_NAME"];
        $this->Name = Util::SnakeToCamelCase($this->NameInDb);
        $this->IsForeignKey = strpos($properties["COLUMN_KEY"], "MUL") !== FALSE;
        $this->IsPrimaryKey = strpos($properties["COLUMN_KEY"], "PRI") !== FALSE;
        $this->HasChanged = false;
        $this->HasReferences = false;
        $this->DataType = $properties["DATA_TYPE"];
        $this->IsNullable = strpos($properties["IS_NULLABLE"], "YES") !== FALSE;
        $this->AutoIncrement = strpos($properties["EXTRA"], "auto_increment") !== FALSE;
    }

    private function buildForeignObject($tableName, $tableSchema){
        aflModel::Debug("Building foreign object for '$tableSchema.$tableName.$this->NameInDb'.");
        $res = Cache::Retrieve([$tableName, $tableSchema, $this->NameInDb]);
        if(!$res) {
            aflModel::Debug("Schema not in cache, querying database.");
            $query = "SELECT
                	  REFERENCED_TABLE_NAME,
                	  REFERENCED_COLUMN_NAME
                  FROM
                	  information_schema.KEY_COLUMN_USAGE
                  WHERE
                	  TABLE_NAME = ?
                  AND TABLE_SCHEMA = ?
                  AND COLUMN_NAME = ?
                  AND REFERENCED_COLUMN_NAME IS NOT NULL";
            $res = SDB::EscRead($query, array($tableName, $tableSchema, $this->NameInDb));
            Cache::Store([$tableName, $tableSchema, $this->NameInDb], $res);
        }
        if(count($res) < 1) return $this->IsForeignKey = false;
        $this->ForeignObject =  aflModel::Create($res[0]["REFERENCED_TABLE_NAME"]);
    }

    function __toString(){
        return (string)$this->Value;
    }
}

class ExternalReference {

    public $ReferencedTable, $ReferencedColumn, $Objects, $Definition, $ColumnReference, $TableReference;
    
    function __construct($data){
        aflModel::Debug("Constructing external reference with data: " . json_encode($data));
        $this->Definition = $this->getDefinition($data["TABLE_SCHEMA"], $data["TABLE_NAME"]);
        $this->ReferencedTable = $data["REFERENCED_TABLE_NAME"];
        $this->ReferencedColumn = $data["REFERENCED_COLUMN_NAME"];
        $this->ColumnReference = $data["COLUMN_NAME"];
        $this->TableReference = $data["TABLE_NAME"];
    }
    // TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_TABLE_SCHEMA
    private function getDefinition($schema, $table){
       return aflModel::Create($table);
    }

    public function New($dataArray = null){
        $tempObj = clone $this->Definition;
        array_push($this->Objects, $tempObj);
        if($dataArray) $tempObj->SetData($dataArray);
        return $tempObj;
    }
}

class Cache {
    private static $store = array();

    public static function Store(Array $params, Array $data) {
        aflModel::Debug("Saving to cache array.");
        $key = implode(".", $params);
        self::$store[$key] = $data;
    }

    public static function Retrieve($params){
        $key = implode(".", $params);
        $ret = isset(self::$store[$key]) ? self::$store[$key] : false;
        if($ret) aflModel::Debug("Cache hit!");
        return  $ret;
    }
}

class DBO {
    private $table,$fields,$values,$joins,$where,$orderby,$limit,$offset;
    private $fieldHolders, $valueHolders;
    private $operation;
    public $Error;
    private $lastShackle;

    protected $con;

	public function __construct(){
		try{
			$this->con = new PDO(CFG_DB_DRIVER.":host=".CFG_DB_HOST.";dbname=".CFG_DB_DBNAME.";charset=".CFG_DB_CHARSET, CFG_DB_USER, CFG_DB_PASSWORD);
			$this->con->prepare("SET TEXTSIZE 9145728")->execute();
		}catch(PDOException $e) {
            die($e->getMessage());
		}
	}

    private $errorMsg = [
        "Operation mode not set or not valid.",
        "Invalid paramenter for operation. Expecting associative array.",
        "Unknown operation.",
        "Method `And`|`Or` only can be used after `Where`.",
        "Invalid parameter. Expecting 3 element array or array with 3 elements arrays.",
        "Can't call Where method two times in a chain. Use _And|_Or instead.",
		"Limit value must be numeric.",
		"OrderBy parameter must be a 2 elements array or an array containing two elements arrays."
        ];

    public function Select($table){
        $this->operation = "SELECT";
        $this->table = $table;
        return $this;
    }

    public function Insert($table){
        $this->operation = "INSERT";
        $this->table = $table;
        return $this;
    }

    public function Update($table){
        $this->operation = "UPDATE";
        $this->table = $table;
        return $this;
    }

    public function Delete($table){
        $this->operation = "DELETE";
        $this->table = $table;
        return $this;
    }

    public function SetData($data){
        if(!$this->operation || $this->operation == "DELETE") return $this->setError(0);
        if(($this->operation == "UPDATE" || "INSERT" ) && !is_array($data) && !Util::IsAssociativeArray($data)) return $this->setError(1);

        switch ($this->operation){
            case "SELECT":
                $this->fields = implode(", ",$data);
                break;
            case "INSERT":
                $this->fields = implode(", ",array_keys($data));
                $this->valueHolders = "";
                foreach($data as $k => $v){
                    $this->valueHolders .= ":v{$k}, ";
                    $data[":v".$k] = $v;
                    unset($data[$k]);
                }
                $this->valueHolders = substr($this->valueHolders,0,strrpos($this->valueHolders,", "));
                $this->values = $data;
                break;
            case "UPDATE":
                $this->fields = "";
                foreach($data as $k => $v){
                    $this->fields .= "{$k} = :v{$k}, ";
                    $data[":v".$k] = $v;
                    unset($data[$k]);
                }
                $this->fields = substr($this->fields,0,strrpos($this->fields,", "));
                $this->values = $data;
                break;
            default:
                return $this->setError(2);
        }
        return $this;
    }

	public function Limit($limitNumber){
		if(!is_numeric($limitNumber)) return $this->setError(6);
		$this->limit = $limitNumber;
		return $this;
	}

	public function Offset($offsetNumber){
		if(!is_numeric($offsetNumber)) return $this->setError(6);
		$this->offset = $offsetNumber;
		return $this;
	}

	public function OrderBy($orderByArray){
		if(!is_array($orderByArray)) return setError(7);
		if(is_array($orderByArray[0])){
            foreach($orderByArray as $k => $v){
                $orderByArray[$k] = implode(" ", $v);
            }
			$this->orderby = implode(", ", $orderByArray);
        }else{
            $this->orderby = implode(" ", $orderByArray);
        }
		return $this;
	}

    public function Exec($bringReferences = false){
        $query = $this->buildQuery();
        if(is_array($this->joins))
            foreach($this->joins as $join){
                $query .= " " . $join;
            }
        $query .= $this->where ? " WHERE ".$this->where : "";
        $query .= $this->orderby ? " ORDER BY " . $this->orderby : "";
        $query .= $this->limit ? " LIMIT " . $this->limit : "";
        $query .= $this->offset ? " OFFSET " . $this->offset : "";
        $sth = $this->con->prepare($query);
        $result = $sth->execute($this->values);
        switch($this->operation){
        	case "UPDATE":
        	case "DELETE":
        		return $result;
        		break;
        	case "INSERT":
        		return $result ? $this->con->lastInsertId() : false;
        		break;
        	case "SELECT":
        		//return $result ? $sth->fetchAll($fetchType) : false;
                return $this->resultToModel($sth->fetchAll(\PDO::FETCH_ASSOC), $bringReferences);
        		break;
        }
    }

	private function resultToModel($result, $bringReferences = false){
        if(!$result) return false;
		$objArray = array();
		foreach ($result as $key => $value) {
			$objArray[$key] = aflModel::Create($this->table, $value, $bringReferences);
		}
		return $objArray;
	}

    private function buildQuery(){
        $ret = "";
        switch($this->operation){
            CASE "SELECT":
				$fields = !$this->fields ? "*" : $this->fields;
                $ret = "SELECT {$fields} FROM {$this->table}";
                break;
            CASE "INSERT":
                $ret = "INSERT INTO {$this->table} ({$this->fields}) VALUES ($this->valueHolders)";
                break;
            CASE "UPDATE":
                $ret = "UPDATE {$this->table} SET {$this->fields}";
                break;
            CASE "DELETE":
                $ret = "DELETE FROM {$this->table}";
                break;
            default:
                return $this->setError(2);
        }
        return $ret;
    }

    public function Where($conditionData,$logicalOperator = null){
        if($this->operation == "INSERT") return $this->setError(0);
        if(!is_array($conditionData)) return $this->setError(4);
        if($this->where && !$logicalOperator) return $this->setError(5);
        $this->where = $logicalOperator ? $this->where . $logicalOperator : $this->where;
        if(is_array($conditionData[0])){
            $multiClause = " (";
            foreach($conditionData as $w){
                $multiClause .= $this->buildWhereClause($w) . " AND ";
            }
            $multiClause = substr($multiClause,0,strrpos($multiClause," AND "));
            $this->where .= $multiClause . ")";
        }else{
            $this->where .= $this->buildWhereClause($conditionData);
        }
        $this->lastShackle = "Where";
        return $this;
    }

    private function buildWhereClause($arrClause){
        if($arrClause[1] == "is null" || $arrClause[1] == "is not null")
            return $arrClause[0] . " " . $arrClause[1];
        $identifier = ":we".substr_count($this->where,":we");
        $whereClause = "{$arrClause[0]} {$arrClause[1]} {$identifier}{$arrClause[0]}";
        $this->values[$identifier.$arrClause[0]] = $arrClause[2];
        return $whereClause;
    }

    public function _And($conditionData){
        if( !($this->lastShackle == "Where") ) return $this->setError(3);
        $this->Where($conditionData," AND ");
        return $this;
    }

    public function _Or($conditionData){
        if( !($this->lastShackle == "Where") ) return $this->setError(3);
        $this->Where($conditionData," OR ");
        return $this;
    }

    private function setError($msg){
        $this->Error = $this->errorMsg[$msg];
        return false;
    }
}

class SDB{
	/** @var \PDO */
	protected static $con;
	protected static $initialized = false;
    public static $LastError;
    private static $debugChannel;

	private static function initialize(){
	    if(self::$initialized) return;
		self::$con = new PDO(CFG_DB_DRIVER.":host=".CFG_DB_HOST.";dbname=".CFG_DB_DBNAME.";charset=".CFG_DB_CHARSET, CFG_DB_USER, CFG_DB_PASSWORD);
		self::$con->prepare("SET TEXTSIZE 9145728")->execute();
		self::$initialized = true;
    }
    
    public static function SetDebugChannel(Callable $callback) {
        if(!is_callable($callback)) return;
        self::$debugChannel = $callback;
    }

	public static function Read($query,$arrayType = PDO::FETCH_ASSOC){
	    self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute();
		if($result){
			return $STH->fetchAll($arrayType);
		}else{
			return false;
		}
	}

	public static function Write($query){
	    self::initialize();
		$STH = self::$con->prepare($query);
		$result = $STH->execute();
		return $result;
	}

	/**
	 * @param string $query
	 * @param array $data
	 * @param int $arrayType
	 * @param bool $debug
	 * @return array|bool
	 */
	public static function EscRead(String $query, Array $data, $arrayType = PDO::FETCH_ASSOC){
        $time = microtime(true);
		self::initialize();
		$STH = self::$con->prepare($query);
        $result = $STH->execute($data);
        $time = round( (microtime(true) - $time) * 1000 );
		self::$LastError = $STH->errorInfo();
		if(self::$debugChannel) {
            $debugCb = self::$debugChannel;
            $debugCb([
                "query" => $query,
                "params" => $data,
                "time" => $time
            ]);
        }
		if($result){
			return $STH->fetchAll($arrayType);
		}else{
			return false;
		}
	}

	/**
	 * @param string $query
	 * @param array $data
	 * @param bool $debug
	 * @return bool
	 */
	public static function EscWrite(String $query, Array $data){
        $time = microtime(true);
		self::initialize();
		$STH = self::$con->prepare($query);
        $result = $STH->execute($data);
        self::$LastError = $STH->errorInfo();
        $time = round( (microtime(true) - $time) * 1000 );
        if(self::$debugChannel) {
            $debugCb = self::$debugChannel;
            $debugCb([
                "query" => $query,
                "params" => $data,
                "time" => $time
            ]);
        }
		if(strpos($query, "INSERT") === 0) return self::$con->lastInsertId();
		return !!$result;
	}

	public static function CloseConnection(){
		self::$con = null;
		self::$initialized = false;
	}
}

class Util {
    public static function CamelToSnakeCase($input){
        $input = preg_replace_callback("/([a-z 1-9])([A-Z])/", function($match){
            return $match[1] . "_" . strtolower($match[2]);
        },$input);
        if(preg_match("/([a-z 1-9])([A-Z])/", $input))
            return self::CamelToSnakeCase($input);
        return strtolower($input);
    }

    public static function SnakeToCamelCase($input){
        return ucfirst(preg_replace_callback("/(_)([a-z])/", function($matches){
            return strtoupper($matches[2]);
        }, $input));
    }

    public static function IsAssociativeArray($arr)	{
		if (array() === $arr) return false;
		return array_keys($arr) !== range(0, count($arr) - 1);
	}
}
