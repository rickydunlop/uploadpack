<?php
/**
 * This file is a part of UploadPack - a plugin that makes file uploads in CakePHP as easy as possible. 
 * 
 * UploadBehavior
 * 
 * UploadBehavior does all the job of saving files to disk while saving records to database. For more info read UploadPack documentation.
 *
 * joe bartlett's lovingly handcrafted tweaks add several resize modes. see "more on styles" in the documentation.
 *
 * @author Michał Szajbe (michal.szajbe@gmail.com) and joe bartlett (contact@jdbartlett.com)
 * @link http://github.com/szajbus/uploadpack
 */
class UploadBehavior extends ModelBehavior {
  
  private static $__settings = array();
  
  protected $toWrite = array();
  
  protected $toDelete = array();
  
  protected $maxWidthSize = false;

  public function setup($model, $settings = array()) {
    $defaults = array(
      'path' => ':webroot/upload/:model/:id/:style-:basename.:extension',
      'styles' => array(),
      'resizeToMaxWidth' => false,
      'quality' => 95,
      'overwrite' => false
    );
    
    foreach ($settings as $field => $array) {
      self::$__settings[$model->name][$field] = array_merge($defaults, $array);
    }
  }
  
  public function beforeSave($model) {
    $this->_reset();
    foreach (self::$__settings[$model->name] as $field => $settings) {
      if (!empty($model->data[$model->name][$field]) && is_array($model->data[$model->name][$field]) && file_exists($model->data[$model->name][$field]['tmp_name'])) {
        if (!empty($model->id)) {
          $this->_prepareToDeleteFiles($model, $field, true);
        }
        $this->_prepareToWriteFiles($model, $field);
      }
    }
    return true;
  }
  
  public function afterSave($model, $create) {
    if (!$create) {
      $this->_deleteFiles($model);
    }
    $this->_writeFiles($model);
  }
  
  public function beforeDelete($model) {
    $this->_reset();
    $this->_prepareToDeleteFiles($model);
    return true;
  }
  
  public function afterDelete($model) {
    $this->_deleteFiles($model);
  }

  public function beforeValidate($model) {
    foreach (self::$__settings[$model->name] as $field => $settings) {
    if (isset($model->data[$model->name][$field])) {
      $data = $model->data[$model->name][$field];

      if ((empty($data) || is_array($data) && empty($data['tmp_name'])) && !empty($settings['urlField']) && !empty($model->data[$model->name][$settings['urlField']])) {
          $data = $model->data[$model->name][$settings['urlField']];
      }
      
      if (!is_array($data)) {
          $model->data[$model->name][$field] = $this->_fetchFromUrl($data);
      }
    }
    }
    return true;
  }

  protected function _reset() {
    $this->toWrite = null;
    $this->toDelete = null;
  }
  
  protected function _fetchFromUrl($url) {
    $data = array('remote' => true);
    $data['name'] = end(explode('/', $url));
    $data['tmp_name'] = tempnam(sys_get_temp_dir(), $data['name']) . '.' . end(explode('.', $url));

    App::uses('HttpSocket', 'Network/Http');
    $httpSocket = new HttpSocket();

    $raw = $httpSocket->get($url);
    $response = $httpSocket->response;

    $data['size'] = strlen($raw);
    $data['type'] = reset(explode(';', $response['header']['Content-Type']));

    file_put_contents($data['tmp_name'], $raw);
    return $data;
  }

/**
 * Prepare files to be written
 * 
 * Cleans the filename making it url friendly
 * Checks path is writable
 * If overwrite is true, it checks to see if the file exists
 */
  protected function _prepareToWriteFiles($model, $field) {
    $pathinfo = pathinfo($model->data[$model->name][$field]['name']);
    extract($pathinfo);

    $filename = $this->_cleanFilename($filename);
    $basename = $filename.'.'.$extension;

    $settings = $this->_interpolate($model, $field, $basename, 'original');

    if (!$this->_isWritable($settings['path'])) {
      throw new DirectoryException('Directory is not writable: ' . $dir);
    }

    if($settings['overwrite'] === false){
      if(file_exists($settings['path'])){
        $filename .= '_'.uniqid();
        $basename = $filename.'.'.$extension;
        $settings = $this->_interpolate($model, $field, $basename, 'original');
      }
    }

    $model->data[$model->name][$field]['name'] = $basename;
    $model->data[$model->name][$field]['settings'] = $settings;

    $this->toWrite[$field] = $model->data[$model->name][$field];
    unset($model->data[$model->name][$field]);

    $model->data[$model->name][$field.'_file_name'] = $this->toWrite[$field]['name'];
    $model->data[$model->name][$field.'_file_size'] = $this->toWrite[$field]['size'];
    $model->data[$model->name][$field.'_content_type'] = $this->toWrite[$field]['type'];
  }
  
  protected function _writeFiles($model) {
    if (!empty($this->toWrite)) {
      foreach ($this->toWrite as $field => $toWrite) {
        $move = !empty($toWrite['settings']['remote']) ? 'rename' : 'move_uploaded_file';
        if ($move($toWrite['tmp_name'], $toWrite['settings']['path'])) {
          // Some bug with the wrong permissions on upload
          if (!empty($toWrite['settings']['remote'])) {
            chmod($toWrite['settings']['path'], 0644);
          }
          if($this->maxWidthSize) {
            $this->_resize($toWrite['settings']['path'], $toWrite['settings']['path'], $this->maxWidthSize.'w', $toWrite['settings']['quality']);
          }
          foreach ($toWrite['settings']['styles'] as $style => $geometry) {
            $newSettings = $this->_interpolate($model, $field, $toWrite['name'], $style);
            if($this->_isWritable($newSettings['path'])){
              $this->_resize($toWrite['settings']['path'], $newSettings['path'], $geometry, $toWrite['settings']['quality']);
            }
          }
        }
      }
    }
  }

/**
 * Checks to see if a directory exists and is writable
 * It then tries to create the directory
 * 
 * @param string $dir The directory to check
 * @return boolean true if successful
 * @throws DirectoryException
 */
  protected function _isWritable($dir) {
    $dir = dirname($dir);
    if (is_dir($dir) && is_writable($dir)) {
      return true;
    }

    if (mkdir($dir)) {
      return true;
    }
    return false;
  }

/**
 * Makes a filename URL friendly by using Inflector::slug
 * 
 * @return string the cleaned filename
 */
  protected function _cleanFilename($filename){
    return Inflector::slug($filename);
  }
  
  protected function _prepareToDeleteFiles($model, $field = null, $forceRead = false) {
    $needToRead = true;
    if ($field === null) {
      $fields = array_keys(self::$__settings[$model->name]);
      foreach ($fields as &$field) {
        $field .= '_file_name';
      }
    } else {
      $field .= '_file_name';
      $fields = array($field);
    }
    
    if (!$forceRead && !empty($model->data[$model->alias])) {
      $needToRead = false;
      foreach ($fields as $field) {
        if (!array_key_exists($field, $model->data[$model->alias])) {
          $needToRead = true;
          break;
        }
      }
    }
    if ($needToRead) {
      $data = $model->find('first', array('conditions' => array($model->alias.'.'.$model->primaryKey => $model->id), 'fields' => $fields, 'callbacks' => false));
    } else {
      $data = $model->data;
    }
    if (is_array($this->toDelete)) {
      $this->toDelete = array_merge($this->toDelete, $data[$model->alias]);
    } else {
      $this->toDelete = $data[$model->alias];
    }
    $this->toDelete['id'] = $model->id;
  }
  
  protected function _deleteFiles($model) {
    foreach (self::$__settings[$model->name] as $field => $settings) {
      if (!empty($this->toDelete[$field.'_file_name'])) {
        $styles = array_keys($settings['styles']);
        $styles[] = 'original';
        foreach ($styles as $style) {
          $settings = $this->_interpolate($model, $field, $this->toDelete[$field.'_file_name'], $style);
          if (file_exists($settings['path'])) {
            @unlink($settings['path']);
          }
        }
      }
    }
  }
  
  protected function _interpolate($model, $field, $filename, $style) {
    return self::interpolate($model->name, $model->id, $field, $filename, $style);
  }
  
  public static function interpolate($modelName, $modelId, $field, $filename, $style = 'original', $defaults = array()) {
    $pathinfo = pathinfo($filename);
    $interpolations = array_merge(array(
      'app' => preg_replace('/\/$/', '', APP),
      'webroot' => preg_replace('/\/$/', '', WWW_ROOT),
      'model' => Inflector::tableize($modelName),
      'basename' => !empty($filename) ? $pathinfo['filename'] : null,
      'extension' => !empty($filename) ? $pathinfo['extension'] : null,
      'id' => $modelId,
      'style' => $style,
      'attachment' => Inflector::pluralize($field),
      'hash' => md5((!empty($filename) ? $pathinfo['filename'] : "") . Configure::read('Security.salt'))
    ), $defaults);
    $settings = self::$__settings[$modelName][$field];
    $keys = array('path', 'url', 'default_url');
    foreach ($interpolations as $k => $v) {
      foreach ($keys as $key) {
        if (isset($settings[$key])) {
          $settings[$key] = preg_replace('/\/{2,}/', '/', str_replace(":$k", $v, $settings[$key]));
        }
      }
    }
    return $settings;
  }

  protected function _resize($srcFile, $destFile, $geometry, $quality = 95) {
    copy($srcFile, $destFile);
    @chmod($destFile, 0777);
    $pathinfo = pathinfo($srcFile);
    $src = null;
    $createHandler = null;
    $outputHandler = null;
    switch (strtolower($pathinfo['extension'])) {
      case 'gif':
        $createHandler = 'imagecreatefromgif';
        $outputHandler = 'imagegif';
        break;
      case 'jpg':
      case 'jpeg':
        $createHandler = 'imagecreatefromjpeg';
        $outputHandler = 'imagejpeg';
        break;
      case 'png':
        $createHandler = 'imagecreatefrompng';
        $outputHandler = 'imagepng';
        $quality = null;
        break;
      default:
          return false;
    }
    if ($src = @$createHandler($destFile)) {
      $srcW = imagesx($src);
      $srcH = imagesy($src);

      // determine destination dimensions and resize mode from provided geometry
      if (preg_match('/^\\[[\\d]+x[\\d]+\\]$/', $geometry)) {
        // resize with banding
        list($destW, $destH) = explode('x', substr($geometry, 1, strlen($geometry)-2));
        $resizeMode = 'band';
      } elseif (preg_match('/^[\\d]+x[\\d]+$/', $geometry)) {
        // cropped resize (best fit)
        list($destW, $destH) = explode('x', $geometry);
        $resizeMode = 'best';
      } elseif (preg_match('/^[\\d]+w$/', $geometry)) {
        // calculate heigh according to aspect ratio
        $destW = (int)$geometry-1;
        $resizeMode = false;
      } elseif (preg_match('/^[\\d]+h$/', $geometry)) {
        // calculate width according to aspect ratio
        $destH = (int)$geometry-1;
        $resizeMode = false;
      } elseif (preg_match('/^[\\d]+l$/', $geometry)) {
        // calculate shortest side according to aspect ratio
        if ($srcW > $srcH) $destW = (int)$geometry-1;
        else $destH = (int)$geometry-1;
        $resizeMode = false;
      }
      if (!isset($destW)) $destW = ($destH/$srcH) * $srcW;
      if (!isset($destH)) $destH = ($destW/$srcW) * $srcH;
  
      // determine resize dimensions from appropriate resize mode and ratio
      if ($resizeMode == 'best') {
        // "best fit" mode
        if ($srcW > $srcH) {
          if ($srcH/$destH > $srcW/$destW) $ratio = $destW/$srcW;
          else $ratio = $destH/$srcH;
        } else {
          if ($srcH/$destH < $srcW/$destW) $ratio = $destH/$srcH;
          else $ratio = $destW/$srcW;
        }
        $resizeW = $srcW*$ratio;
        $resizeH = $srcH*$ratio;
      }
      elseif ($resizeMode == 'band') {
        // "banding" mode
        if ($srcW > $srcH) $ratio = $destW/$srcW;
        else $ratio = $destH/$srcH;
        $resizeW = $srcW*$ratio;
        $resizeH = $srcH*$ratio;
      }
      else {
        // no resize ratio
        $resizeW = $destW;
        $resizeH = $destH;
      }
      
      $img = imagecreatetruecolor($destW, $destH);
      imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
      imagecopyresampled($img, $src, ($destW-$resizeW)/2, ($destH-$resizeH)/2, 0, 0, $resizeW, $resizeH, $srcW, $srcH);
      $outputHandler($img, $destFile, $quality);
      return true;
    }
    return false;
  }
  
  public function attachmentMinSize($model, $value, $min) {
    $value = array_shift($value);
    if (!empty($value['tmp_name'])) {
      return (int)$min <= (int)$value['size'];
    }
    return true;
  }
  
  public function attachmentMaxSize($model, $value, $max) {
    $value = array_shift($value);
    if (!empty($value['tmp_name'])) {
      return (int)$value['size'] <= (int)$max;
    }
    return true;
  }
  
  public function attachmentContentType($model, $value, $contentTypes) {
    $value = array_shift($value);
    if (!is_array($contentTypes)) {
      $contentTypes = array($contentTypes);
    }

    if (!empty($value['tmp_name'])) {
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      if ($finfo) {
        $mimetype = $finfo->file($value['tmp_name']);
      } else {
        $mimetype = $value['type'];
      }

      foreach ($contentTypes as $contentType) {
        if (substr($contentType, 0, 1) == '/') {
          if (preg_match($contentType, $mimetype)) {
            return true;
          }
        } elseif ($contentType == $mimetype) {
          return true;
        }
      }
      return false;
    }
    return true;
  }

  
  public function attachmentPresence($model, $value) {
    $keys = array_keys($value);
    $field = $keys[0];
    $value = array_shift($value);
    
    if (!empty($value['tmp_name'])) {
      return true;
    }
    
    if (!empty($model->id)) {
      if (!empty($model->data[$model->alias][$field.'_file_name'])) {
        return true;
      } elseif (!isset($model->data[$model->alias][$field.'_file_name'])) {
        $existingFile = $model->field($field.'_file_name', array($model->primaryKey => $model->id));
        if (!empty($existingFile)) {
          return true;
        }
      }
    }
    return false;
  }

  public function minWidth($model, $value, $minWidth) {
    return $this->_validateDimension($value, 'min', 'x', $minWidth);
  }

  public function minHeight($model, $value, $minHeight) {
    return $this->_validateDimension($value, 'min', 'y', $minHeight);
  }

  public function maxWidth($model, $value, $maxWidth) {
    $keys = array_keys($value);
    $field = $keys[0];
    $settings = self::$__settings[$model->name][$field];
    if($settings['resizeToMaxWidth'] && !$this->_validateDimension($value, 'max', 'x', $maxWidth)) {
      $this->maxWidthSize = $maxWidth;
      return true;
    } else {
      return $this->_validateDimension($value, 'max', 'x', $maxWidth);
    }
  }

  public function maxHeight($model, $value, $maxHeight) {
    return $this->_validateDimension($value, 'max', 'y', $maxHeight);
  }

  protected function _validateDimension($upload, $mode, $axis, $value) {
    $upload = array_shift($upload);
    $func = 'images'.$axis;
    if(!empty($upload['tmp_name'])) {
      $createHandler = null;
      if($upload['type'] == 'image/jpeg') {
        $createHandler = 'imagecreatefromjpeg';
      } else if($upload['type'] == 'image/gif') {
        $createHandler = 'imagecreatefromgif';
      } else if($upload['type'] == 'image/png') {
        $createHandler = 'imagecreatefrompng';
      } else {
        return false;
      }
    
      if($img = $createHandler($upload['tmp_name'])) {
        switch ($mode) {
          case 'min':
            return $func($img) >= $value;
            break;
          case 'max':
            return $func($img) <= $value;
            break;
        }
      }
    }
    return false;
  }
}

class DirectoryException extends CakeException {};