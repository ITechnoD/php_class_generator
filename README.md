# PHP class generator

Simple tool to generate PHP code for working with database objects

1. Include `utils.php` before you include generated classes
2. Run generator

## Usage

```
./generate_class.php ClassName sql_table_name id_field_name +getter_and_setter [+...] -only_getter [-...] [meta meta_table_name rel_field key_field value_field] > output.php
```
- Arg 1 is a desired class name
- Arg 2 is a SQL table name
- Arg 3 is an ID SQL table field name
- The following args are SQL table field names:
  - +field_name will add getFieldName() and setFieldName($value)
  - -field_name will only add getFieldName()
- With extra "meta table_name rel_field key_field value_field" args specified, additional metadata get/set/save/load functions will be generated.
  - table_name is a name of metadata MySQL table
  - rel_field is a name of a field that stores object ID
  - key_field is a name of a field that stores meta key
  - value_field is a name of a field that stores meta value

Output PHP class will have the following methods:

- `getFieldName()` and optionally `setFieldName($value)` for a field called `field_name`
- `__construct($id, $row=null)`: you can initialize new class instance by specifying an $id attribute or row data
- `save()` method to save changes
- `getMeta($key)`/`setMeta($key, $value)` to work with metadata

## Example table structure and output PHP code

SQL create table statements

```sql
/* Users */
create table `users` (
    `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `flags` BIGINT NOT NULL DEFAULT 0,
    `ctime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mtime` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `user_name` VARCHAR(128),
    `email` VARCHAR(80) UNIQUE NULL,
    `password` VARCHAR(40)
) Engine=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

/* Users metadata */
create table `user_meta` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `key` VARCHAR(16),
    `value` VARCHAR(128),
    PRIMARY KEY (`user_id`,`key`),
    FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`)
        ON DELETE CASCADE
) Engine=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

Command line

```bash
generate_class.php User users id +flags -ctime -mtime +user_name +email +password meta user_meta user_id key value > user.php
```

Output PHP code

```php
<?php

class User {
    private $id;
    private $ctime;
    private $mtime;
    private $flags;
    private $user_name;
    private $email;
    private $password;
    private $_loaded = false;
    private $_modified = false;

    private $_m_loaded = false;
    private $_m_modified = [];
    private $_META = Array();
    /** id field getter */
    public function getId() {return $this->id;}

    /** ctime field getter */
    public function getCtime() {return $this->ctime;}

    /** mtime field getter */
    public function getMtime() {return $this->mtime;}

    /** flags field getter */
    public function getFlags() {return $this->flags;}
    /** flags field setter */
    public function setFlags($value) {
        if ($this->flags == $value) return;
        $this->flags = $value;
        $this->_modified = true;
    }

    /** user_name field getter */
    public function getUserName() {return $this->user_name;}
    /** user_name field setter */
    public function setUserName($value) {
        if ($this->user_name == $value) return;
        $this->user_name = $value;
        $this->_modified = true;
    }

    /** email field getter */
    public function getEmail() {return $this->email;}
    /** email field setter */
    public function setEmail($value) {
        if ($this->email == $value) return;
        $this->email = $value;
        $this->_modified = true;
    }

    /** password field getter */
    public function getPassword() {return $this->password;}
    /** password field setter */
    public function setPassword($value) {
        if ($this->password == $value) return;
        $this->password = $value;
        $this->_modified = true;
    }

    public function __construct($id=null, $row=null) {
        if ($id !== null) {
            $this->id = $id;
            $this->load($row);
        } else {
            $this->_modified = true;
        }
    }

    private function create() {
        $sql = "INSERT INTO `users`
                (`flags`,`user_name`,`email`,`password`) VALUES (:flags,:user_name,:email,:password)";
        $params = Array (
                "flags" => $this->flags,
                "user_name" => $this->user_name,
                "email" => $this->email,
                "password" => $this->password,
            );
        $this->id = exec_query($sql, $params, false, true);
        $this->_loaded = true;
        $this->_modified = false;
    }

    private function update() {
        $sql = "UPDATE `users` SET
            `flags` = :flags,
            `user_name` = :user_name,
            `email` = :email,
            `password` = :password
             WHERE `id` = :id";
        $params = Array (
                "flags" => $this->flags,
                "user_name" => $this->user_name,
                "email" => $this->email,
                "password" => $this->password,
                "id" => $this->id,
            );
        exec_query($sql, $params, false);
        $this->_modified = false;
    }

    private function loadMeta() {
        if ($this->_m_loaded) return;
        $this->_META = Array();
        $sql = "SELECT `key` k,`value` v FROM `user_meta` WHERE `user_id` = ?";
        $params = [$this->id];
        $res = exec_query($sql, $params);
        foreach ($res as $row) {
            $this->_META[$row["k"]] = $row["v"];
        }
        $this->_m_loaded = true;
    }

    private function saveMeta() {
        if (!count($this->_m_modified)) return;
        $values = "";
        $params = [];
        foreach ($this->_m_modified as $key=>$true) {
            $values .= ",(?,?,?)";
            $params []= $this->id;
            $params []= $key;
            $params []= $this->_META[$key];
        }
        $values = substr($values,1);
        $sql = "REPLACE INTO `user_meta` (`user_id`,`key`,`value`) VALUES $values";
        $res = exec_query($sql, $params, false);
        $this->_m_modified = Array();
    }

    public function getMeta($key) {
        if (!$this->_m_loaded) $this->loadMeta();
        if (!array_key_exists($key, $this->_META)) return null;
        return $this->_META[$key];
    }
    public function setMeta($key, $value) {
        if (array_key_exists($key, $this->_META)
            && $this->_META[$key] == $value) return;
        $this->_META[$key] = $value;
        $this->_m_modified[$key] = true;
    }

    public function load($row=null) {
        if ($this->_loaded && !$this->_modified) return;
        if ($row === null) {
            $sql = "SELECT `ctime`,`mtime`,`flags`,`user_name`,`email`,`password`
                    FROM `users`
                    WHERE `id` = :id";
            $params = Array("id"=>$this->id);
            $result = exec_query($sql,$params);
            if (!count($result)) {
                throw new Exception("Entry not found");
            }
            $row = $result[0];
        };
        list(
            $this->ctime,
            $this->mtime,
            $this->flags,
            $this->user_name,
            $this->email,
            $this->password
        ) = Array (
            $row["ctime"],
            $row["mtime"],
            $row["flags"],
            $row["user_name"],
            $row["email"],
            $row["password"]);
        $this->_loaded = true;
        $this->loadMeta();
    }

    public function save() {
        if (!$this->_modified) return;
        if ($this->_loaded) $this->update();
        else $this->create();
        $this->saveMeta();
    }

}

?>
```
