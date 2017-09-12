## Require the following defines to work:
```
define("CFG_DB_DRIVER", "mysql");
define("CFG_DB_HOST", "localhost");
define("CFG_DB_DBNAME", "name");
define("CFG_DB_CHARSET", "utf8");
define("CFG_DB_USER", "user");
define("CFG_DB_PASSWORD", 'password');
```
## Usage:

**aflModel** class has only one static method, `Create`, that returns an object
reflecting a model of a determined table in the database. The object's properties can then be read or modified directly using a **camel case**
representation of the field name in the database, for example, a field in database *user_id*
could be accessed or modified in the object model like this: `$obj->UserId`.

All foreign keys will be also reflected recursively, creating a property within the object using the name of the table the foreign key refers to.
## Examples:

> Creates a model object of the table.

`$objModel = aflModel::Create("table_name");`

> Properties can be read directly like using just the camel case representation of the database field name.

`echo $objModel->SomeTableField;`

> Properties can be modified the same way.

`$objModel->YetAnotherField = "Some value";`

> And then saved to database

`$objModel->Save();`

> It is also posible to create a model object and set all or some fields on its creation using an associative array. Camelcase or snakecase can be used indistinctly in the associative array.

```
$objModel = aflModel->Create("table_name", array(
    "field_1" => 1,
    "Field2" => "value"
));
$objModel->Save();
```

> Table records can be retrieved from database into a model object using the method **GetById**. This method takes one parameter, that will be used to match against the primary key of the table. In case that there are multiple primary keys, and array of values should be passed.

```
$objModel = aflModel->Create("table_name");
$objModel->GetById(3);
echo $objModel->FieldName;
```
