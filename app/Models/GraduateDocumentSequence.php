<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GraduateDocumentSequence extends Model
{
    protected $fillable = ['year', 'document_type', 'last_number'];
}
