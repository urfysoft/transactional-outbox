<?php

use Urfysoft\TransactionalOutbox\Http\Controllers\MessageWebhookController;

Route::get('/api/v1/urfysoft/transactional-outbox', MessageWebhookController::class)
    ->middleware([
        'auth:sanctum',
        'ability:' . config('transactional-outbox.sanctum.required_ability')
    ])
    ->name('api.v1.urfysoft.transactional-outbox');
