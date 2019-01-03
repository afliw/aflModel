# What is it

**aflModel** is a simple aproach to interact with a database using dynamically created model objects.
This model objects are created on runtime through a class with minimal provided information, there's no need to declare anything else beforehand.

It's a fast way to retrieve and modify data on a database without having to declare classes for each table on the database.

## Features

* Create model objects on the fly gathering the required information from the database schema automatically.
* Recursively get all related foreign objects, allowing to interact with them too.
* Translates all database fields and tables name to a camelcase representation.
* Get a table data and its relations' data into an object by its primary key with a simple method.
* Transform model object and its relations in a simple associative array.
* Save all modified information in model object with a simple method.

### Upcoming

* A simil-linq sintax for easy database interaction.
* Maybe datatype validation, altough I think is better to leave that to the database engine for now.

## Requirements

* PDO PHP extension.

### Requires the following defines to work

```php
define("CFG_DB_DRIVER", "mysql");
define("CFG_DB_HOST", "localhost");
define("CFG_DB_DBNAME", "name");
define("CFG_DB_CHARSET", "utf8");
define("CFG_DB_USER", "user");
define("CFG_DB_PASSWORD", 'password');
```

## Usage

**aflModel** class has only one static method, `Create`, that returns an object
reflecting a model of a determined table in the database. The object's properties can then be read or modified directly using a **camel case**
representation of the field name in the database, for example, a field in database *user_id*
could be accessed or modified in the object model like this: `$obj->UserId`.

All foreign keys will be also reflected recursively, creating a property within the object using the name of the table the foreign key refers to.

## Examples

> Create a model object of a table named "_table_name_".

```php
$objModel = aflModel::Create("table_name");
```

> Properties can be read directly using just the camelcase representation of the database field name (in this case, the field on database is called "_some_table_field_").

```php
echo $objModel->SomeTableField;
```

> Properties can be modified the same way.

```php
$objModel->YetAnotherField = "Some value";
```

> And then saved to database

```php
$objModel->Save();
```

> It is also posible to create a model object and set all or some fields on its creation using an associative array. Camelcase or snakecase can be used indistinctly in the associative array.

```php
$objModel = aflModel::Create("table_name", array(
    "field_1" => 1,
    "Field2" => "value"
));
$objModel->Save();
```

> Table records can be retrieved from database into a model object using the method **GetById**. This method takes one parameter, that will be used to match against the primary key of the table. In case that there are multiple primary keys, and array of values should be passed.

```php
$objModel = aflModel::Create("table_name");
$objModel->GetById(3);
echo $objModel->FieldName;
```

> All model object created are build with their foreign related objects inside of them. In this example, we will use two tables. The table "_person_" has a foreign id, "_document_id_" refering the field "_id_" on table "_document_".

```php
$personObj = aflModel::Create("person");
// To get the foreign id, just use the camelcase name in database
echo $personObj->DocumentId;
// To access the foreign object, use the table name refered.
echo $personObj->Document->Number;
// If foreign key is changed, the object updates itself with the new data from database.
$personObj->DocumentId = 3;
// This would output the new document number
echo $personObj->Document->Number;
```

# Work in progress...

