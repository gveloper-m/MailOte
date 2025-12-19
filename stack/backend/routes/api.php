<?php

use App\Http\Controllers\GmailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is working!',
        'timestamp' => now(),
    ]);
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'message' => 'Laravel API is running',
    ]);
});

// Gmail OAuth Routes
Route::prefix('gmail')->group(function () {
    Route::get('/auth-url', [GmailController::class, 'getAuthUrl']);
    Route::post('/login', [GmailController::class, 'login']);
    Route::get('/callback', [GmailController::class, 'callback']);
    Route::get('/emails', [GmailController::class, 'getEmails']);
    Route::get('/email/{emailId}', [GmailController::class, 'getEmail']);
    Route::get('/emails/unsubscribe', [GmailController::class, 'findUnsubscribeEmails']);
    Route::post('/unsubscribe/emails', [GmailController::class, 'unsubscribeFromEmails']);
    Route::get('/deleted', [GmailController::class, 'getDeletedEmails']);
    Route::get('/statistics', [GmailController::class, 'statistics']);
    Route::get('/senders', [GmailController::class, 'senders']);
    Route::post('/senders/show', [GmailController::class, 'showSenderEmails']);
    Route::post('/senders/delete', [GmailController::class, 'deleteSenderEmails']);
    Route::get('/attachments', [GmailController::class, 'getEmailsWithAttachments']);
    Route::get('/attachment/download/{gmail_id}/{part_id}', [GmailController::class, 'downloadAttachment'])->name('gmail.attachment.download');
    Route::get('/export/pdf', [GmailController::class, 'exportAllEmailsToPdf']);
    Route::get('/export/csv', [GmailController::class, 'exportAllEmailsToCsv']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
