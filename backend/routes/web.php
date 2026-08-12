<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Sirve la SPA de React (resources/frontend) para cualquier ruta que no sea
// /api, /storage o /build — el enrutamiento real de página a página lo hace
// react-router-dom en el navegador. Sin Blade: SpaController genera el HTML
// directo en PHP (ver app/Http/Controllers/SpaController.php).
Route::get('/{any?}', [SpaController::class, 'index'])->where('any', '^(?!api|storage|build).*$');
