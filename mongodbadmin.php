<?php

/**
 * PHP MongoDB Admin
 *
 * Administrate a MongoDB server:
 *
 *   * List, create and delete databases
 *   * List, create and delete collections
 *   * List, create, edit and delete documents
 *
 * Documents are editable with raw PHP code.
 *
 * http://github.com/jwage/php-mongodb-admin
 * http://www.twitter.com/jwage
 *
 * @author Jonathan H. Wage <jonwage@gmail.com>
 * @Theme Ted Veatch
 */

header('Pragma: no-cache');

$server = array(
  'mongodb://localhost:27017',
//  'mongodb://example.org:27017',
);

$options = array(
  'connect' => true
);

$readOnly = false;

if (!class_exists('Mongo'))
{
  die("Mongo support required. Install mongo pecl extension with 'pecl install mongo; echo \"extension=mongo.so\" >> php.ini'");
}
try
{
  $mongo = new Mongo(getServer($server), $options);
}
catch (MongoConnectionException $ex)
{
  error_log($ex->getMessage());
  die("Failed to connect to MongoDB");
}


/**
 * Get the current MongoDB server.
 *
 * @param mixed $server
 * @return string $server
 */
function getServer($server)
{
  if (is_array($server)) {
    return (isset($_COOKIE['mongo_server']) && isset($server[$_COOKIE['mongo_server']])) ? $server[$_COOKIE['mongo_server']] : $server[0];
  } else {
    return $server;
  }
}

/**
 * Render a document preview for the black code box with referenced
 * linked to the collection and id for that database reference.
 *
 * @param string $document
 * @return string $preview
 */
function renderDocumentPreview($mongo, $document)
{
  $document = prepareMongoDBDocumentForEdit($document);
  $preview = linkDocumentReferences($mongo, $document);
  $preview = print_r($preview, true);
  return $preview;
}

/**
 * Change any references to other documents to include a html link
 * to that document and collection. Used by the renderDocumentPreview() function.
 *
 * @param array $document
 * @return array $document
 */
function linkDocumentReferences($mongo, $document)
{
  foreach ($document as $key => $value) {
    if (is_array($value)) {
      if (isset($value['$ref'])) {
        $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($value['$ref']);
        $id = $value['$id'];

        $ref = findMongoDbDocument($value['$id'], $_REQUEST['db'], $value['$ref']);
        if (!$ref) {
          $ref = findMongoDbDocument($value['$id'], $_REQUEST['db'], $value['$ref'], true);
        }

        $refDb = isset($value['$db']) ? $value['$db'] : $_REQUEST['db'];

        $document[$key]['$ref'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.urlencode($refDb).'&collection='.$value['$ref'].'">'.$value['$ref'].'</a>';

        if ($ref['_id'] instanceof MongoId) {
          $document[$key]['$id'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.urlencode($refDb).'&collection='.$value['$ref'].'&id='.$value['$id'].'">'.$value['$id'].'</a>';
        } else {
          $document[$key]['$id'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.urlencode($refDb).'&collection='.$value['$ref'].'&id='.$value['$id'].'&custom_id=1">'.$value['$id'].'</a>';
        }

        if (isset($value['$db'])) {
            $document[$key]['$db'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.urlencode($refDb).'">'.$refDb.'</a>';
        }
      } else {
        $document[$key] = linkDocumentReferences($mongo, $value);
      }
    }
  }
  return $document;
}

/**
 * Prepare user submitted array of PHP code as a MongoDB
 * document that can be saved.
 *
 * @param mixed $value
 * @return array $document
 */
function prepareValueForMongoDB($value)
{
  $customId = isset($_REQUEST['custom_id']);

  if (is_string($value)) {
    $value = preg_replace('/\'_id\' => \s*MongoId::__set_state\(array\(\s*\)\)/', '\'_id\' => new MongoId("' . (isset($_REQUEST['id']) ? $_REQUEST['id'] : '') . '")', $value);
    $value = preg_replace('/MongoId::__set_state\(array\(\s*\)\)/', 'new MongoId()', $value);
    $value = preg_replace('/MongoDate::__set_state\(array\(\s*\'sec\' => (\d+),\s*\'usec\' => \d+,\s*\)\)/m', 'new MongoDate($1)', $value);
    $value = preg_replace('/MongoBinData::__set_state\(array\(\s*\'bin\' => \'(.*?)\',\s*\'type\' => ([1,2,3,5,128]),\s*\)\)/m', 'new MongoBinData(\'$1\', $2)', $value);

    eval('$value = ' . $value . ';');

    if (!$value) {
      header('location: ' . $_SERVER['HTTP_REFERER'] . ($customId ? '&custom_id=1' : null));
      exit;
    }
  }

  $prepared = array();
  foreach ($value as $k => $v) {
    if ($k === '_id' && !$customId) {
      $v = new MongoId($v);
    }

    if ($k === '$id' && !$customId) {
      $v = new MongoId($v);
    }

    if (is_array($v)) {
      $prepared[$k] = prepareValueForMongoDB($v);
    } else {
      $prepared[$k] = $v;
    }
  }
  return $prepared;
}

/**
 * Prepare a MongoDB document for the textarea so it can be edited.
 *
 * @param array $value
 * @return array $prepared
 */
function prepareMongoDBDocumentForEdit($value)
{
  $prepared = array();
  foreach ($value as $key => $value) {
    if ($key === '_id') {
      $value = (string) $value;
    }
    if ($key === '$id') {
      $value = (string) $value;
    }
    if (is_array($value)) {
      $prepared[$key] = prepareMongoDBDocumentForEdit($value);
    } else {
      $prepared[$key] = $value;
    }
  }
  return $prepared;
}

/**
 * Search for a MongoDB document based on the id
 *
 * @param string $id The ID to search for
 * @param string $db The db to use
 * @param string $collection The collection to search in
 * @param bool $forceCustomId True to force a custom id search
 * @return mixed $document
 *
 */
function findMongoDbDocument($id, $db, $collection, $forceCustomId = false)
{
  global $mongo;

  $collection = $mongo->selectDB($db)->selectCollection($collection);

  if (isset($_REQUEST['custom_id']) || $forceCustomId) {
    if (is_numeric($id)) {
      $id = (int) $id;
    }
    $document =$collection->findOne(array('_id' => $id));
  } else {
    $document = $collection->findOne(array('_id' => new MongoId($id)));
  }

  return $document;
}

// Actions
try {
  // SEARCH BY ID
  if (isset($_REQUEST['search']) && !is_object(json_decode($_REQUEST['search']))) {
    $customId = false;
    $document = findMongoDbDocument($_REQUEST['search'], $_REQUEST['db'], $_REQUEST['collection']);

    if (!$document) {
      $document = findMongoDbDocument($_REQUEST['search'], $_REQUEST['db'], $_REQUEST['collection'], true);
      $customId = true;
    }

    if (isset($document['_id'])) {
      $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];

      if ($customId) {
        header('location: ' . $url . '&custom_id=true');
      } else {
        header('location: ' . $url);
      }
    }
  }

  // DELETE DB
  if (isset($_REQUEST['delete_db']) && $readOnly !== true) {
    $mongo
      ->selectDB($_REQUEST['delete_db'])
      ->drop();

    header('location: ' . $_SERVER['PHP_SELF']);
    exit;
  }

  // CREATE DB
  if (isset($_REQUEST['create_db']) && $readOnly !== true) {
    $mongo->selectDB($_REQUEST['create_db'])->createCollection('__tmp_collection_');
    $mongo->selectDB($_REQUEST['create_db'])->dropCollection('__tmp_collection_');

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['create_db']));
    exit;

  }

  // CREATE DB COLLECTION
  if (isset($_REQUEST['create_collection']) && $readOnly !== true) {
    $mongo
      ->selectDB($_REQUEST['db'])
      ->createCollection($_REQUEST['create_collection']);

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['create_collection']);
    exit;
  }

  // DELETE DB COLLECTION
  if (isset($_REQUEST['delete_collection']) && $readOnly !== true) {
    $mongo
      ->selectDB($_REQUEST['db'])
      ->selectCollection($_REQUEST['delete_collection'])
      ->drop();

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']));
    exit;
  }

  // DELETE DB COLLECTION DOCUMENT
  if (isset($_REQUEST['delete_document']) && $readOnly !== true) {
    $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

    if (isset($_REQUEST['custom_id'])) {
        $id = $_REQUEST['delete_document'];
      if (is_numeric($id)) {
        $id = (int) $id;
      }
      $collection->remove(array('_id' => $id));
    } else {
      $collection->remove(array('_id' => new MongoId($_REQUEST['delete_document'])));
    }

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection']);
    exit;
  }

  // DELETE DB COLLECTION DOCUMENT FIELD AND VALUE
  if (isset($_REQUEST['delete_document_field']) && $readOnly !== true) {
    $coll = $mongo
      ->selectDB($_REQUEST['db'])
      ->selectCollection($_REQUEST['collection']);

    $document = findMongoDbDocument($_REQUEST['id'], $_REQUEST['db'], $_REQUEST['collection']);
    unset($document[$_REQUEST['delete_document_field']]);
    $coll->save($document);

    $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
    header('location: ' . $url);
    exit;
  }

  // INSERT OR UPDATE A DB COLLECTION DOCUMENT
  if (isset($_POST['save']) && $readOnly !== true) {
    $customId = isset($_REQUEST['custom_id']);
    $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

    $document = prepareValueForMongoDB($_REQUEST['value']);
    $collection->save($document);

    $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
    header('location: ' . $url . ($customId ? '&custom_id=1' : null));
    exit;
  }

// Catch any errors and redirect to referrer with error
} catch (Exception $e) {
  header('location: '.$_SERVER['HTTP_REFERER'].'&error='.htmlspecialchars($e->getMessage()));
  exit;
}
?>
<html>
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>PHP MongoDB Admin</title>
    <link rel="shortcut icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAAAQAAAAAAAAAAAAAAAAA
AAAAAAA9Umn/K0Jb/y9FXv8vRV7/L0Ve/y9FXv8vRV7/OU9m/0JWbf8vRV7/L0Ve/y9FXv8vRV7/
L0Ve/y9FXv8vRV7/LkRe/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/zhOZv9GWnD/HzdS/x83Uv8f
N1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv9SbnD/Vm92/x83
Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/HzdS/x83Uv9MaWX/UKgz
/0SOJf9NZmn/HzdS/x83Uv8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/x83Uv86UWP/
Sqcc/1SsOP9HkiT/QpAe/zNKX/8fN1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8i
OVT/VZBF/06pJf9XrkH/SZUo/0OSH/9SfVT/LURa/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83
Uv8fN1L/Ql5g/0qmGf9TrDL/WrFJ/0mWKv9IlSf/SZIt/0NjWv8fN1L/HzdS/x83Uv8fN1L/LkRe
/x83Uv8fN1L/HzdS/0J0Rf9NqCD/Va05/16yT/9LmCv/SZYo/0mWKf89cED/HzdS/x83Uv8fN1L/
HzdS/y5EXv8fN1L/HzdS/x83Uv9AeT3/UKko/1evQf9htFX/TZks/0qYKv9KmCn/OnI7/x83Uv8f
N1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/RG1V/0+rLf9asEj/ZbZa/02aLv9MmSz/TJgu/0Rr
VP8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/zhTXv9Vqzr/XbJP/2i4X/9OnDD/S5kr
/1GKR/83UF7/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8fN1L/WIxc/16zUf9sumT/
T50x/0aZJf9GY2L/HzdS/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/HzdS/zZOYf9f
tFT/cLxp/02dLf9WkUr/HzdS/x83Uv8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/x83
Uv8fN1L/XXx2/2y8Zf9Qmjj/NEpg/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS
/x83Uv8fN1L/HzdS/x83Uv9cgnD/Q1tn/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv89Umn/
K0Jb/y9FXv8vRV7/L0Ve/y9FXv8vRV7/OlBl/zRJYf8vRV7/L0Ve/y9FXv8vRV7/L0Ve/y9FXv8v
RV7/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAA==" type="image/x-icon" />
	<LINK href="mongo.css" rel="stylesheet" type="text/css">
    
  </head>

  <body>

  <div id="content">
    <h1>
      <a href="http://docs.mongodb.org"><div id="mongoLogo"></div></a>
      <?php if (is_array($server)): ?>
        <?php if (count($server) > 1): ?>
          <select id="server" onChange="document.cookie='mongo_server='+this[this.selectedIndex].value;document.location.reload();return false;">
            <?php foreach ($server as $key => $s): ?>
              <option value="<?php echo $key ?>"<?php if (isset($_COOKIE['mongo_server']) && $_COOKIE['mongo_server'] == $key): ?> selected="selected"<?php endif; ?>><?php echo preg_replace('/\/\/(.*):(.*)@/', '//$1:*****@', $s); ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <?php echo $server[0] ?>
        <?php endif; ?>
      <?php else: ?>
        <?php echo $server ?>
      <?php endif; ?>
    </h1>
    <?php if (isset($_REQUEST['error'])): ?>
      <div class="error">
        <?php echo $_REQUEST['error'] ?>
      </div>
    <?php endif; ?>

<?php // START ACTION TEMPLATES ?>

<?php // CREATE AND LIST DBs TEMPLATE ?>
<?php if ( ! isset($_REQUEST['db'])): ?>

  <?php if ($readOnly !== true): ?>
    <div id="create_form">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <label for="create_db_field">Create Database</label>
        <input type="text" name="create_db" id="create_db_field" />
        <input type="submit" name="save" value="Save" />
      </form>
    </div>
  <?php endif; ?>

  <h2>Databases</h2>

  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Collections</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php $dbs = $mongo->listDBs() ?>
      <?php foreach ($dbs['databases'] as $db): if ($db['name'] === 'local' || $db['name'] === 'admin') continue; ?>
        <tr>
          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($db['name']) ?>"><?php echo $db['name'] ?></a></td>
          <td><?php echo count($mongo->selectDb($db['name'])->listCollections()) ?></td>

          <?php if ($readOnly !== true): ?>
            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?delete_db=<?php echo urlencode($db['name']) ?>" onClick="return confirm('Are you sure you want to delete this database?');">Delete</a></td>
          <?php else: ?>
            <td>&nbsp;</td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php // CREATE AND LIST DB COLLECTIONS ?>
<?php elseif (isset($_REQUEST['db']) && ! isset($_REQUEST['collection'])): ?>

  <?php if ($readOnly !== true): ?>
    <div id="create_form">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>" method="POST">
        <label for="create_collection_field">Create Collection</label>
        <input type="text" name="create_collection" id="create_collection_field" />
        <input type="submit" name="create" value="Save" />
      </form>
    </div>
  <?php endif; ?>

  <h2>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
    <?php echo $_REQUEST['db'] ?>
  </h2>
  <table>
    <thead>
      <tr>
        <th>Collection Name</th>
        <th>Documents</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php $collections = $mongo->selectDB($_REQUEST['db'])->listCollections() ?>
      <?php foreach ($collections as $collection): ?>
        <tr>
          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $collection->getName() ?>"><?php echo $collection->getName() ?></a></td>
          <td><?php echo $collection->count(); ?></td>

         <?php if ($readOnly !== true): ?>
            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>&delete_collection=<?php echo $collection->getName() ?>" onClick="return confirm('Are you sure you want to delete this collection?');">Delete</a></td>
          <?php else: ?>
            <td>&nbsp;</td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php // CREATE AND LIST DB COLLECTION DOCUMENTS ?>
<?php elseif ( ! isset($_REQUEST['id']) || isset($_REQUEST['search'])): ?>

    <?php
    $max = 20;
    $page = isset($_REQUEST['page']) ? $_REQUEST['page'] : 1;
    $limit = $max;
    $skip = ($page - 1) * $max;

    if (isset($_REQUEST['search']) && is_object(json_decode($_REQUEST['search']))) {
      $search = json_decode($_REQUEST['search'], true);

      $cursor = $mongo
        ->selectDB($_REQUEST['db'])
        ->selectCollection($_REQUEST['collection'])
        ->find($search)
        ->limit($limit)
        ->skip($skip);
    } else {
      $cursor = $mongo
        ->selectDB($_REQUEST['db'])
        ->selectCollection($_REQUEST['collection'])
        ->find()
        ->limit($limit)
        ->skip($skip)
        ->sort(array('_id' => 1));
    }

    $total = $cursor->count();
    $pages = ceil($total / $max);

    if ($pages && $page > $pages) {
      header('location: ' . $_SERVER['HTTP_REFERER']);
      exit;
    }
    ?>

    <h2>
      <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
      <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>"><?php echo $_REQUEST['db'] ?></a> >>
      <?php echo $_REQUEST['collection'] ?> (<?php echo $cursor->count() ?> Documents)
    </h2>

    <?php if ($pages > 1): ?>
      <div id="pager">
        <?php echo $pages ?> pages. Go to page
        <input type="text" name="page" size="4" value="<?php echo $page ?>" onChange="javascript: location.href = '<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?><?php if (isset($_REQUEST['search'])): ?>&search=<?php echo urlencode($_REQUEST['search']) ?><?php endif; ?>&page=' + this.value;" />
        <input type="button" name="go" value="Go" />
      </div>
    <?php endif; ?>

    <div id="search">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="GET">
        <input type="hidden" name="db" value="<?php echo $_REQUEST['db'] ?>" />
        <input type="hidden" name="collection" value="<?php echo $_REQUEST['collection'] ?>" />
        <label for="search_input">Search</label>
        <input type="text" id="search_input" name="search" size="36"<?php  echo isset($_REQUEST['search']) ? ' value="' . htmlspecialchars($_REQUEST['search']) . '"': '' ?> />
        <input type="submit" name="submit_search" value="Search" />
      </form>
    </div>

    <table>
      <thead>
        <th colspan="3">ID</th>
      </thead>
      <tbody>
        <?php foreach ($cursor as $document): ?>
          <tr>
            <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>"><?php echo (string) $document['_id'] ?></a></td>
            <?php else: ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>&custom_id=1"><?php echo (string) $document['_id'] ?></a></td>
            <?php endif; ?>
            <td>
              <?php
                if (isset($search)) {
                  $displayValues = array();

                  $searchKeys = isset($search['$query']) ? $search['$query'] : $search;

                  foreach ($searchKeys as $fieldName => $searchQuery) {
                    if ($fieldName != '_id' && $fieldName[0] != '$' && isset($document[$fieldName])) {
                      $fieldValue = $document[$fieldName];

                      if (!is_array($fieldValue) && !is_object($fieldValue)) {
                        $displayValues[] = $fieldName . ': ' . substr(str_replace("\n", '', htmlspecialchars($fieldValue)), 0, 100);
                      }
                    }
                  }

                  echo implode(' - ', $displayValues);
                }

                if (!isset($displayValues) || !count($displayValues)) {
                  foreach ($document as $fieldName => $fieldValue) {
                    if ($fieldName != '_id' && !is_array($fieldValue) && !is_object($fieldValue)) {
                      echo $fieldName . ': ' . substr(str_replace("\n", '', htmlspecialchars($fieldValue)), 0, 100);
                      break;
                    }
                  }
                }
              ?>
            </td>
            <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId && $readOnly !== true): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
            <?php elseif ($readOnly !== true): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($readOnly !== true): ?>
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <?php if (isset($document)): ?>
          <input type="hidden" name="values[_id]" value="<?php echo $document['_id'] ?>" />

          <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId): ?>
            <input type="hidden" name="custom_id" value="1" />
          <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($_REQUEST as $k => $v): ?>
          <input type="hidden" name="<?php echo $k ?>" value="<?php echo $v ?>" />
        <?php endforeach; ?>

        <h2>Create New Document</h2>
        <textarea name="value"></textarea>
        <input type="submit" name="save" value="Save" />
      </form>
    <?php endif; ?>

<?php // EDIT DB COLLECTION DOCUMENT ?>
<?php else: ?>

<h2>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>"><?php echo $_REQUEST['db'] ?></a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>"><?php echo $_REQUEST['collection'] ?></a> >>
    <?php echo $_REQUEST['id'] ?>
    </h2>
    <?php $document = findMongoDbDocument($_REQUEST['id'], $_REQUEST['db'], $_REQUEST['collection']); ?>

    <pre><code><?php echo renderDocumentPreview($mongo, $document) ?></code></pre>

    <?php $prepared = prepareMongoDBDocumentForEdit($document) ?>

    <?php if ($readOnly !== true): ?>
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <input type="hidden" name="values[_id]" value="<?php echo $document['_id'] ?>" />

        <?php foreach ($_REQUEST as $k => $v): ?>
          <input type="hidden" name="<?php echo $k ?>" value="<?php echo $v ?>" />
        <?php endforeach; ?>

        <h2>Edit Document</h2>
        <textarea name="value"><?php echo var_export($prepared, true) ?></textarea>
        <input type="submit" name="save" value="Save" />
      </form>
    <?php endif; ?>
    <br/>
    <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId && $readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php elseif ($readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php endif; ?>

    <?php endif; ?>
<?php // END ACTION TEMPLATES ?>

      <p id="footer"><span class="footer">Created by <a href="http://www.twitter.com/jwage" target="_BLANK">Jonathan H. Wage</a> | Theme by Ted Veatch</span></p>
    </div>
  </body>
</html>
