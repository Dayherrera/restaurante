<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CompanySetting extends Model {
 protected $guarded=['id'];
 public static function current(): self {return static::findOrFail(1);}
}
