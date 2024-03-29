<?PHP
/**
 * Copyright (c) 2018 HostFission, All Rights Reserved.
 * Author: Geoffrey McRae <geoff@hostfission.com>
 */

namespace GnifBot;

class Record
{
  protected $dsClass;
  private   $invalid = false;
  private   $readOnly;
  private   $newRecord;
  private   $row;
  private   $modified;
  private   $dontDelete = false;

  /**
   * Constructor
   *
   * @warning Do not construct records directly, use either DataSource#fetch or
   * DataSource#createRecord.
   *
   * @param string $dsClass
   *   The data source class that this record belongs to
   *
   * @param array $row
   *   An array of `column => value` key pairs
   */
  public function __construct(string $dsClass, array $row)
  {
    $this->dsClass = $dsClass;
    $this->row     = $row;

    $primary = $dsClass::getPrimaryKey();
    $this->readOnly  = !array_key_exists($primary, $row);
    $this->newRecord = $this->readOnly || is_null($row[$primary]);
  }

  /**
   * PHP magic __set method
   */
  public function __set(string $column, $value)
  {
    if ($this->readOnly)
      throw new Exception('The record is read only');

    if (!$this->dsClass::isValidColumn($column))
      throw new Exception('Invalid column \'' . $column . '\'');

    $setter = "set_$column";
    if (method_exists($this, $setter))
    {
      $value = call_user_func(
        [$this, $setter],
        $value
      );
    }

    $type = $this->dsClass::getColumnType($column);
    if (!is_null($value) || (!($type & DataSource::FLAG_MOD_NULLABLE) && is_null($value)))
      switch($this->dsClass::getColumnType($column) & DataSource::FLAG_TYPE_MASK)
      {
        case DataSource::FLAG_TYPE_INT :
          if (!($type & DataSource::FLAG_MOD_BIG))
            $value = (int)$value;
          break;

        case DataSource::FLAG_TYPE_STR : $value = (string)$value; break;
        case DataSource::FLAG_TYPE_DEC : $value = (float )$value; break;
        case DataSource::FLAG_TYPE_TXT : $value = (string)$value; break;
        case DataSource::FLAG_TYPE_BOOL: $value = (bool  )$value; break;
        case DataSource::FLAG_TYPE_BIN : $value = (string)$value; break;
      }

    if (!array_key_exists($column, $this->row) || $this->row[$column] !== $value)
      $this->modified = true;

    $this->row[$column] = $value;
  }

  /**
   * PHP magic __get method
   */
  public function __get(string $column)
  {
    if (!array_key_exists($column, $this->row) && !$this->dsClass::isValidColumn($column))
      throw new Exception('Invalid column \'' . $column . '\'');

    $value = array_key_exists($column, $this->row) ? $this->row[$column] : null;

    if ($this->isNew() && is_null($value) && $this->dsClass::getPrimaryKey() == $column)
      throw new Exception('The record is new and does not yet have a primary key value');

    $getter = "get_$column";
    if (method_exists($this, $getter))
    {
      $update = false;
      $this->row[$column] = $value = call_user_func_array(
        [$this, $getter], [$value, &$update]
      );

      if ($update)
        $this->modified = true;

      return $value;
    }

    // could be a calc column, ignore errors
    try
    {
      $type = $this->dsClass::getColumnType($column);
      if (!is_null($value) || (!($type & DataSource::FLAG_MOD_NULLABLE) && is_null($value)))
        switch($this->dsClass::getColumnType($column) & DataSource::FLAG_TYPE_MASK)
        {
          case DataSource::FLAG_TYPE_INT :
            if (!($type & DataSource::FLAG_MOD_BIG))
              $value = (int)$value;
            break;

          case DataSource::FLAG_TYPE_STR : $value = (string)$value; break;
          case DataSource::FLAG_TYPE_DEC : $value = (float )$value; break;
          case DataSource::FLAG_TYPE_TXT : $value = (string)$value; break;
          case DataSource::FLAG_TYPE_BOOL: $value = (bool  )$value; break;
          case DataSource::FLAG_TYPE_BIN : $value = (string)$value; break;
        }
    }
    catch(Exception $e)
    {
    }

    return $value;
  }

  /**
   * PHP magic __isset method
   */
  public function __isset(string $column)
  {
    $exists = array_key_exists($column, $this->row);
    if (!$exists)
      return false;

    if ($this->newRecord && $this->dsClass::getPrimaryKey() == $column && is_null($this->row[$column]))
      return false;

    return true;
  }

  /**
   * Returns if the record is new and doesn't exist in the database yet
   *
   * @retval bool
   */
  public function isNew() : bool
  {
    return $this->newRecord;
  }

  /**
   * Returns if the record has been modified and not yet saved
   *
   * @retval bool
   */
  public function isModified() : bool
  {
    return $this->modified;
  }

  public function IsInvalid() : bool
  {
    return $this->invalid;
  }

  public function IsReadOnly(): bool
  {
    return $this->readOnly;
  }

  /**
   * Reload the record from the database
   *
   * @retval bool
   * @throws WMS#Exception
   */
  public function reload() : bool
  {
    if ($this->invalid)
      throw new Exception("Record is invalid");

    if ($this->newRecord)
      throw new Exception("Unable to reload a new record");

    return $this->dsClass::reloadRecord($this, array_keys($this->row));
  }

  /**
   * Save the record to the database
   *
   * @retval bool
   * @throws WMS#Exception
   */
  public function save() : bool
  {
    if ($this->readOnly)
      throw new Exception('The record is read only');

    if ($this->invalid)
      throw new Exception("Record is invalid");

    if (!$this->newRecord && !$this->modified)
      return true;

    if ($this->dsClass::saveRecord($this))
    {
      $this->newRecord = false;
      return true;
    }
    return false;
  }

  /**
   * Inherit delete logic but do not actually delete the record.
   *
   * @warning Do not call this method directly, it is for use by DataSource as
   * the DataSource will perform a bulk delete to improve database performance.
   */
  final public function logicalDelete() : void
  {
    if ($this->readOnly)
      throw new Exception('The record is read only');

    $this->dontDelete = true;
    $this->delete();
    $this->dontDelete = false;
  }

  /**
   * Delete the record from the database
   *
   * @retval bool
   * @throws WMS#Exception
   */
  public function delete() : bool
  {
    if ($this->readOnly)
      throw new Exception('The record is read only');

    if (!$this->dontDelete)
    {
      if (!$this->dsClass::deleteRecord($this))
        return false;
    }

    $this->invalid = true;
    return true;
  }
}
?>