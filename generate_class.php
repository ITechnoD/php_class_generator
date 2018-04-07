#!/usr/bin/env php
<?php

define("EXECQUERY", isset($_SERVER["EXECQUERY"])?$_SERVER["EXECQUERY"]:"exec_query");
const MODE_ARRAY=0;
const MODE_INSERT=1;
const MODE_SET=2;
const MODE_LIST=3;
const MODE_RES=4;

function GeneratePHPFile($content) {
    return "<?php\n\n${content}\n?>\n";
}
function generateGetterSetter($sql_name, $indent, $setter=false) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    $ccname = toCamelCase($sql_name, 1);
    if ($setter) {
        return
            $ind_out."/** ${sql_name} field setter */\n".
            $ind_out."public function set${ccname}(\$value) {\n".
                $ind_in."if (\$this->${sql_name} == \$value) return;\n".
                $ind_in."\$this->${sql_name} = \$value;\n".
                $ind_in."\$this->_modified = true;\n".
            $ind_out."}\n";
    } else {
        return
            $ind_out."/** ${sql_name} field getter */\n".
            $ind_out."public function get${ccname}() {return \$this->${sql_name};}\n";
    }
}
function generateConstructor($idfield, $indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    $ind_in2 = str_repeat("    ", $indent+2);
    return
        $ind_out."public function __construct(\$$idfield=null, \$row=null) {\n".
            $ind_in."if (\$$idfield !== null) {\n".
                $ind_in2."\$this->$idfield = \$$idfield;\n".
                $ind_in2."\$this->load(\$row);\n".
            $ind_in."} else {\n".
                $ind_in2."\$this->_modified = true;\n".
            $ind_in."}\n".
        $ind_out."}\n";
}

function generateList($params, $indent=1, $mode=0) {
    $ind0 = str_repeat("    ", $indent);
    $ind1 = str_repeat("    ", $indent+1);
    $res = "";
    switch ($mode) {
      case MODE_ARRAY:
        $res = "Array (\n";
        foreach ($params as $param) {
            $res .= $ind1."\"$param\" => \$this->$param,\n";
        }
        $res .= $ind0.");\n";
        break;
      case MODE_INSERT:
        $into = [];
        $values = [];
        foreach ($params as $param) {
            $into []= "`$param`";
            $values []= ":$param";
            $res = "(".implode(",",$into).") VALUES (".implode(",",$values).")";
        }
        break;
      case MODE_SET:
        $lines = [];
        foreach ($params as $param) {
            $lines []= $ind1."`$param` = :$param";
        }
        $res = implode(",\n", $lines);
        break;
      case MODE_LIST:
        $lines = [];
        foreach ($params as $param) {
            $lines []= $ind1."\$this->$param";
        }
        $res = $ind0."list(\n";
        $res .= implode(",\n", $lines)."\n";
        $res .= $ind0.")";
        break;
      case MODE_RES:
        $lines = [];
        foreach ($params as $param) {
            $lines []= $ind1."\$row[\"$param\"]";
        }
        $res = "Array (\n".implode(",\n", $lines).");\n";
        break;
    }
    return $res;
}
function generateLoad($tblname, $idfield, $fields, $indent=1, $loadMeta) { #TODO loadMeta
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    $ind_in2 = str_repeat("    ", $indent+2);
    $ind_in3 = str_repeat("    ", $indent+3);
    return
        $ind_out."public function load(\$row=null) {\n".
            $ind_in."if (\$this->_loaded && !\$this->_modified) return;\n".
            $ind_in."if (\$row === null) {\n".
            $ind_in2."\$sql = \"SELECT `".implode("`,`",$fields)."`\n".
            $ind_in2."        FROM `${tblname}`\n".
            $ind_in2."        WHERE `${idfield}` = :${idfield}\";\n".
            $ind_in2."\$params = Array(\"${idfield}\"=>\$this->${idfield});\n".
            $ind_in2."\$result = ".EXECQUERY."(\$sql,\$params);\n".
            $ind_in2."if (!count(\$result)) {\n".
                $ind_in3."throw new Exception(\"Entry not found\");\n".
            $ind_in2."}\n".
            $ind_in2."\$row = \$result[0];\n".
            $ind_in."};\n".
            generateList($fields, $indent+1, MODE_LIST)." = ".
                    generateList($fields, $indent+1, MODE_RES).
            $ind_in."\$this->_loaded = true;\n".
            ($loadMeta
                ?$ind_in."\$this->loadMeta();\n"
                :"").
        $ind_out."}\n";
}
function generateCreate($tblname, $idfield, $fields, $indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    
    return
        $ind_out."private function create() {\n".
            $ind_in."\$sql = \"INSERT INTO `${tblname}`\n".
            $ind_in."        ".generateList($fields, $indent+1, MODE_INSERT)."\";\n".
            $ind_in."\$params = ".generateList($fields, $indent+2, MODE_ARRAY).
            $ind_in."\$this->${idfield} = ".EXECQUERY."(\$sql, \$params, false, true);\n".
            $ind_in."\$this->_loaded = true;\n".
            $ind_in."\$this->_modified = false;\n".
        $ind_out."}\n";
}
function generateUpdate($tblname, $idfield, $fields, $indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    
    return
        $ind_out."private function update() {\n".
            $ind_in."\$sql = \"UPDATE `${tblname}` SET\n".
            generateList($fields, $indent+1, MODE_SET)."\n".
            $ind_in."     WHERE `${idfield}` = :${idfield}\";\n".
            $ind_in."\$params = ".generateList(array_merge($fields, [$idfield]), $indent+2, MODE_ARRAY).
            $ind_in.EXECQUERY."(\$sql, \$params, false);\n".
            $ind_in."\$this->_modified = false;\n".
        $ind_out."}\n";
}
function generateSave($indent=1, $saveMeta) { #todo saveMeta
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    return
        $ind_out."public function save() {\n".
            $ind_in."if (!\$this->_modified) return;\n".
            $ind_in."if (\$this->_loaded) \$this->update();\n".
            $ind_in."else \$this->create();\n".
            ($saveMeta
                ?$ind_in."\$this->saveMeta();\n"
                :"").
        $ind_out."}\n";
}
function generateLoadMeta($tbl, $rel, $key, $val, $idfield, $indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    return
        $ind_out."private function loadMeta() {\n".
            $ind_in."if (\$this->_m_loaded) return;\n".
            $ind_in."\$this->_META = Array();\n".
            $ind_in."\$sql = \"SELECT `$key` k,`$val` v FROM `$tbl` WHERE `$rel` = ?\";\n".
            $ind_in."\$params = [\$this->$idfield];\n".
            $ind_in."\$res = ".EXECQUERY."(\$sql, \$params);\n".
            $ind_in."foreach (\$res as \$row) {\n".
            $ind_in."    \$this->_META[\$row[\"k\"]] = \$row[\"v\"];\n".
            $ind_in."}\n".
            $ind_in."\$this->_m_loaded = true;\n".
        $ind_out."}\n";
}
function generateSaveMeta($tbl, $rel, $key, $val, $idfield, $indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    return
        $ind_out."private function saveMeta() {\n".
            $ind_in."if (!count(\$this->_m_modified)) return;\n".
            $ind_in."\$values = \"\";\n".
            $ind_in."\$params = [];\n".
            $ind_in."foreach (\$this->_m_modified as \$key=>\$true) {\n".
            $ind_in."    \$values .= \",(?,?,?)\";\n".
            $ind_in."    \$params []= \$this->$idfield;\n".
            $ind_in."    \$params []= \$key;\n".
            $ind_in."    \$params []= \$this->_META[\$key];\n".
            $ind_in."}\n".
            $ind_in."\$values = substr(\$values,1);\n".
            $ind_in."\$sql = \"REPLACE INTO `$tbl` (`$rel`,`$key`,`$val`) VALUES \$values\";\n".
            $ind_in."\$res = ".EXECQUERY."(\$sql, \$params, false);\n".
            $ind_in."\$this->_m_modified = Array();\n".
        $ind_out."}\n";
}
function generateGetSetMeta($indent=1) {
    $ind_out = str_repeat("    ", $indent);
    $ind_in = str_repeat("    ", $indent+1);
    return
        $ind_out."public function getMeta(\$key) {\n".
            $ind_in."if (!\$this->_m_loaded) \$this->loadMeta();\n".
            $ind_in."if (!array_key_exists(\$key, \$this->_META)) return null;\n".
            $ind_in."return \$this->_META[\$key];\n".
        $ind_out."}\n".
        $ind_out."public function setMeta(\$key, \$value) {\n".
            $ind_in."if (array_key_exists(\$key, \$this->_META)\n".
            $ind_in."    && \$this->_META[\$key] == \$value) return;\n".
            $ind_in."\$this->_META[\$key] = \$value;\n".
            $ind_in."\$this->_m_modified[\$key] = true;\n".
        $ind_out."}\n";
}
function generatePHPClass($name, $tblname, $idfield, $getonly, $getset, $meta) {
    $allfields = array_merge($getonly, $getset);
    $privfields = [];
    $private_section = "";
    $_getset = [];
    foreach (array_merge([$idfield],$allfields) as $f) {
        $private_section .= "    "."private \$$f;\n";
    }
    $_getset []= generateGetterSetter($idfield, 1);
    foreach ($getonly as $g) {
        $_getset []= generateGetterSetter($g, 1);
    }
    foreach ($getset as $g) {
        $_getset []= generateGetterSetter($g, 1)
                    .generateGetterSetter($g, 1, true);
    }
    return
        "class ${name} {\n".
        $private_section.
        "    private \$_loaded = false;\n".
        "    private \$_modified = false;\n\n".
        ($meta["meta"]?
            "    private \$_m_loaded = false;\n".
            "    private \$_m_modified = [];\n".
            "    private \$_META = Array();\n" : "").
        implode("\n", $_getset)."\n".
        generateConstructor($idfield, 1)."\n".
        generateCreate($tblname, $idfield, $getset, 1)."\n".
        generateUpdate($tblname, $idfield, $getset, 1)."\n".
        ($meta["meta"]?
            generateLoadMeta($meta["tbl"], $meta["rel"], $meta["key"], $meta["val"], $idfield, 1)."\n".
            generateSaveMeta($meta["tbl"], $meta["rel"], $meta["key"], $meta["val"], $idfield, 1)."\n".
            generateGetSetMeta(1)."\n"
            :"").
        generateLoad($tblname, $idfield, $allfields, 1, $meta["meta"])."\n".
        generateSave(1, $meta["meta"])."\n".
        "}\n";
}

function uppercase($matches) {
    for ($i = 1; $i < count($matches); $i++) {
        $matches[0] = str_replace($matches[$i],
                                  strtoupper(substr($matches[$i], 1)),
                                  $matches[0]);
    }
    return $matches[0];
}
function toCamelCase($underscored, $capitalizeFirst=false) {
    $res = preg_replace_callback("|.*(_.).*|", "uppercase", $underscored);
    $res = preg_replace_callback("|.*(_.).*|", "uppercase", $res);
    if ($capitalizeFirst) {
        $res = strToUpper(substr($res,0,1)).substr($res,1);
    }
    return $res;
}

function usage($app) {
    echo <<<EOF
USAGE: ${app} ClassName sql_table_name id +getter_and_setter [+...] -only_getter [-...] [meta meta_table_name rel_field key_field value_field]
    Arg 1 is a desired class name
    Arg 2 is a SQL table name
    Arg 3 is an ID SQL table field name
    The following args are SQL table field names:
    +field_name will add getFieldName() and setFieldName(\$value)
    -field_name will only add getFieldName()
    With extra "meta table_name rel_field key_field value_field" args specified, additional metadata get/set/save/load functions will be generated.
        table_name is a name of metadata MySQL table
        rel_field is a name of a field that stores object ID
        key_field is a name of a field that stores meta key
        value_field is a name of a field that stores meta value

EOF;
}

if (count($argv) < 4) {
    usage($argv[0]);
    die();
}
$cls = $argv[1];
$table = $argv[2];
$idfield = $argv[3];
$getonly = [];
$getset = [];
$genmeta = false;
$meta_tblname = null;
$meta_rel_field = null;
$meta_key_field = null;
$meta_value_field = null;
for ($i = 4; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg == "meta") {
        $genmeta = true;
        $meta_tblname = $argv[$i+1];
        $meta_rel_field = $argv[$i+2];
        $meta_key_field = $argv[$i+3];
        $meta_value_field = $argv[$i+4];
        $i += 4;
        continue;
    }
    $s = (substr($arg,0,1) == "+");
    $g = (substr($arg,0,1) == "-");
    $arg = substr($arg, 1);
    if ($s) $getset []= $arg;
    else if ($g) $getonly []= $arg;
    else continue;
}

echo generatePHPFile(generatePHPClass($cls, $table, $idfield, $getonly, $getset,
                                      Array(
                                        "meta" => $genmeta,
                                        "tbl" => $meta_tblname,
                                        "rel" => $meta_rel_field,
                                        "key" => $meta_key_field,
                                        "val" => $meta_value_field
                                      )));

?>
