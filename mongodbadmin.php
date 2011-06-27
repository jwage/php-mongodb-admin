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

        $document[$key]['$ref'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.$refDb.'&collection='.$value['$ref'].'">'.$value['$ref'].'</a>';

        if ($ref['_id'] instanceof MongoId) {
          $document[$key]['$id'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.$refDb.'&collection='.$value['$ref'].'&id='.$value['$id'].'">'.$value['$id'].'</a>';
        } else {
          $document[$key]['$id'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.$refDb.'&collection='.$value['$ref'].'&id='.$value['$id'].'&custom_id=1">'.$value['$id'].'</a>';
        }

        if (isset($value['$db'])) {
            $document[$key]['$db'] = '<a href="'.$_SERVER['PHP_SELF'].'?db='.$refDb.'">'.$refDb.'</a>';
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
      $url = $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];

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

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['create_db']);
    exit;

  }

  // CREATE DB COLLECTION
  if (isset($_REQUEST['create_collection']) && $readOnly !== true) {
    $mongo
      ->selectDB($_REQUEST['db'])
      ->createCollection($_REQUEST['create_collection']);

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['create_collection']);
    exit;
  }

  // DELETE DB COLLECTION
  if (isset($_REQUEST['delete_collection']) && $readOnly !== true) {
    $mongo
      ->selectDB($_REQUEST['db'])
      ->selectCollection($_REQUEST['delete_collection'])
      ->drop();

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db']);
    exit;
  }

  // DELETE DB COLLECTION DOCUMENT
  if (isset($_REQUEST['delete_document']) && $readOnly !== true) {
    $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

    if (isset($_REQUEST['custom_id'])) {
      $collection->remove(array('_id' => $_REQUEST['delete_document']));
    } else {
      $collection->remove(array('_id' => new MongoId($_REQUEST['delete_document'])));
    }

    header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection']);
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

    $url = $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
    header('location: ' . $url);
    exit;
  }

  // INSERT OR UPDATE A DB COLLECTION DOCUMENT
  if (isset($_POST['save']) && $readOnly !== true) {
    $customId = isset($_REQUEST['custom_id']);
    $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

    $document = prepareValueForMongoDB($_REQUEST['value']);
    $collection->save($document);

    $url = $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
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
    <link rel="shortcut icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEACABoBQAAFgAAACgAAAAQAAAAIAAAAAEACAAAAAAAAAEAABILAAASCwAAAAEAAAABAACObVoAkG5bAJFwXgCVdmYAlXZmAJV2ZgCVdmYAo4h4AKOIeACul4oArpeKALKbjwC5pJkAuaSZALmkmQC5pJkAv62jAMGupADFtasAxbWrAMW1qwDKu7IAz8C4ANLEuwDWysQA1srEANbKxADWysQA3dLMAOXc2ADl3NgA6OLdAOji3QDs5%2BMA7OfjAOzn4wDx7OoA8%2FDuAPr49wD6%2BPcA%2Bvj3APAA6wAA9gAAAQH7AAEHAQATAQ0AARkBACUBHwABKwEAOAEyAAE%2BAQBMAUUAAVIBAGABWQABZwEAdQFuAAF8AQCLAYMAAZIBAKEBmgABqQEAuQGxAAHBAQDRAckAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQEDHB0fISQlGBIVFRURAAEBAxwdHyEkJRgSFRUVEQEBAQMcHx8hJSUXFRUVFhAAAQEAGB8hJCUmFxUVFRYMAAEBABUhISQlJhgVFRUXBwEBAQELJCElJiYYFRUVFQMBAQEBByEkJSYmGBUWFwwAAQEBAQEYJSUmJhgVFhcHAQEBAQEBCSYmJiYYFhcQAQEBAQEBAQIYJiYmGBYWAwEBAQEBAQEBByYmJhgXCQEBAQEBAQEBAQEMJiYcDAIBAQEBAQEBAQEBARImEgIBAQEBAQEBAQEBAQEBEQcBAQEBAQEBAQEBAQEBAQICAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA%3D" type="image/x-icon" />
    <style type="text/css">
    html{color:#000;background:#FFF;}body,div,dl,dt,dd,ul,ol,li,h1,h2,h3,h4,h5,h6,pre,code,form,fieldset,legend,input,textarea,p,blockquote,th,td{margin:0;padding:0;}table{border-collapse:collapse;border-spacing:0;}fieldset,img{border:0;}address,caption,cite,code,dfn,em,strong,th,var{font-style:normal;font-weight:normal;}li{list-style:none;}caption,th{text-align:left;}h1,h2,h3,h4,h5,h6{font-size:100%;font-weight:normal;}q:before,q:after{content:'';}abbr,acronym{border:0;font-variant:normal;}sup{vertical-align:text-top;}sub{vertical-align:text-bottom;}input,textarea,select{font-family:inherit;font-size:inherit;font-weight:inherit;}input,textarea,select{*font-size:100%;}legend{color:#000;}
    html { background: #010410; font:13px/1.231 "Lucida Grande",verdana,arial,helvetica,clean,sans-serif;*font-size:small;*font:x-small;}table {font-size:inherit;font:100%;}pre,code,kbd,samp,tt{font-family:monospace;*font-size:108%;line-height:100%;}
    a:link, a:visited, a:active { text-decoration:none; color:#3370C9; outline:none; border:0; }
    a:hover  { color: #00508c; text-decoration:underline; border:0; }

    pre {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      padding: 10px;
      background-color: #222;
      overflow/**/: auto;
      margin-bottom: 15px;
      line-height: 17px;
      font-size: 13px;
      color: #fff;
      font-family: "Bitstream Vera Sans Mono", monospace;
      white-space: pre-wrap;
    }

    pre a {
      color: #fff !important;
      text-decoration: underline !important;
    }

    #content {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      margin-top: 20px;
      margin-bottom: 20px;
      padding: 20px;
      width: 90%;
      margin-left: auto;
      margin-right: auto;
      position:relative;
      background:#fff;
      color: #495a7e;
    }
    #content h1 { font-size: 25px; font-weight: bold; margin-bottom: 15px; }
    #content h2 { font-size: 20px; font-weight: bold; margin-bottom: 15px; margin-top: 10px; }

    #footer {
      margin-top: 15px;
      text-align: center;
      font-weight: bold;
      font-size: 12px;
    }

    #create_form {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      padding: 15px;
      background: #f5f5f5;
      border: 1px solid #ccc;
      width: 400px;
      float: right;
      margin-bottom: 10px;
    }
    #create_form label {
      float: left;
      padding: 4px;
      font-weight: bold;
      margin-right: 10px;
    }
    #pager {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      background: #f5f5f5;
      border: 1px solid #ccc;
      padding: 8px;
      margin-bottom: 15px;
      width: 350px;
      float: left;
    }
    #search {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      background: #f5f5f5;
      border: 1px solid #ccc;
      padding: 8px;
      margin-bottom: 15px;
      width: 400px;
      float: right;
    }
    table {
      background: #333;
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      border-collapse: collapse;
      width: 100%;
    }
    table th {
      color: #fff;
      font-weight: bold;
      padding: 8px;
    }
    table td {
      padding: 8px;
    }
    table td a {
      font-weight: bold;
    }
    table tbody tr {
      background-color: #fff;
      border-bottom: 1px solid #ccc;
    }
    table tbody tr:hover {
    	background-color: #eee;
    }
    .save_button {
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      background-color: #333;
      border: 1px solid #333;
      color: #fff;
      padding: 4px;
      font-weight: bold;
      padding-left: 10px;
      padding-right: 10px;
    }
    .save_button:hover {
      background-color: #ccc;
      border: 1px solid #ccc;
      color: #333;
      cursor: pointer;
    }
    textarea {
      padding: 10px;
      -moz-border-radius: 10px;
      -webkit-border-radius: 10px;
      border-radius: 10px;
      border: 1px solid #ccc;
      width: 100%;
      height: 350px;
      margin-top: 10px;
      margin-bottom: 10px;
    }
    </style>
  </head>

  <body>

  <div id="content">
    <h1>
      PHP MongoDB Admin -
      <?php if (is_array($server)): ?>
        <?php if (count($server) > 1): ?>
          <select id="server" onChange="document.cookie='mongo_server='+this[this.selectedIndex].value;document.location.reload();return false;">
            <?php foreach ($server as $key => $s): ?>
              <option value="<?php echo $key ?>"<?php if (isset($_COOKIE['mongo_server']) && $_COOKIE['mongo_server'] == $key): ?> selected="selected"<?php endif; ?>><?php echo $s ?></option>
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
        <input type="submit" name="save" value="Save" class="save_button" />
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
          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $db['name'] ?>"><?php echo $db['name'] ?></a></td>
          <td><?php echo count($mongo->selectDb($db['name'])->listCollections()) ?></td>

          <?php if ($readOnly !== true): ?>
            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?delete_db=<?php echo $db['name'] ?>" onClick="return confirm('Are you sure you want to delete this database?');">Delete</a></td>
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
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo $_REQUEST['db'] ?>" method="POST">
        <label for="create_collection_field">Create Collection</label>
        <input type="text" name="create_collection" id="create_collection_field" />
        <input type="submit" name="create" value="Save" class="save_button" />
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
        <th>Name</th>
        <th>Documents</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php $collections = $mongo->selectDB($_REQUEST['db'])->listCollections() ?>
      <?php foreach ($collections as $collection): ?>
        <tr>
          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $collection->getName() ?>"><?php echo $collection->getName() ?></a></td>
          <td><?php echo $collection->count(); ?></td>

         <?php if ($readOnly !== true): ?>
            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo $_REQUEST['db'] ?>&delete_collection=<?php echo $collection->getName() ?>" onClick="return confirm('Are you sure you want to delete this collection?');">Delete</a></td>
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
        ->skip($skip);
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
      <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo $_REQUEST['db'] ?>"><?php echo $_REQUEST['db'] ?></a> >>
      <?php echo $_REQUEST['collection'] ?> (<?php echo $cursor->count() ?> Documents)
    </h2>

    <?php if ($pages > 1): ?>
      <div id="pager">
        <?php echo $pages ?> pages. Go to page
        <input type="text" name="page" size="4" value="<?php echo $page ?>" onChange="javascript: location.href = '<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?><?php if (isset($_REQUEST['search'])): ?>&search=<?php echo urlencode($_REQUEST['search']) ?><?php endif; ?>&page=' + this.value;" />
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
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>"><?php echo (string) $document['_id'] ?></a></td>
            <?php else: ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>&custom_id=1"><?php echo (string) $document['_id'] ?></a></td>
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
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
            <?php elseif ($readOnly !== true): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
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
        <input type="submit" name="save" value="Save" class="save_button" />
        <textarea name="value"></textarea>
        <input type="submit" name="save" value="Save" class="save_button" />
      </form>
    <?php endif; ?>

<?php // EDIT DB COLLECTION DOCUMENT ?>
<?php else: ?>

<h2>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo $_REQUEST['db'] ?>"><?php echo $_REQUEST['db'] ?></a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>"><?php echo $_REQUEST['collection'] ?></a> >>
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
        <input type="submit" name="save" value="Save" class="save_button" />
        <textarea name="value"><?php echo var_export($prepared, true) ?></textarea>
        <input type="submit" name="save" value="Save" class="save_button" />
      </form>
    <?php endif; ?>
    <br/>
    <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId && $readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php elseif ($readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . $_REQUEST['db'] . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php endif; ?>

    <?php endif; ?>
<?php // END ACTION TEMPLATES ?>

      <p id="footer">Created by <a href="http://www.twitter.com/jwage" target="_BLANK">Jonathan H. Wage</a></p>
    </div>
  </body>
</html>
