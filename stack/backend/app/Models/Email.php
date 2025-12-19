<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
        'gmail_id',
        'user_id',
        'from',
        'to',
        'subject',
        'date',
        'remote_delete',
        'thread_id',
        'snippet',
        'label_ids',
        'has_attachments',
    ];

    protected $casts = [
        'date' => 'datetime',
        'remote_delete' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function body()
    {
        return $this->hasOne(EmailBody::class);
    }
}
