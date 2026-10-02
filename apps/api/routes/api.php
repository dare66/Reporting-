<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DataController;
use App\Http\Controllers\Api\GovernanceController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QueryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SemanticModelController;
use Illuminate\Support\Facades\Route;

// Prefix: /api/v1 (bootstrap/app.php)

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])->middleware('throttle:mfa');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');
});
Route::post('ingest/webhook/{id}/{token}', [DataController::class, 'webhook'])->middleware('throttle:api');

Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('me/preferences', [AuthController::class, 'updatePreferences']);
    Route::post('me/mfa/setup', [AuthController::class, 'setupMfa']);
    Route::post('me/mfa/enable', [AuthController::class, 'enableMfa']);
    Route::delete('me/mfa', [AuthController::class, 'disableMfa']);
    Route::get('me/sessions', [AuthController::class, 'sessions']);
    Route::delete('me/sessions/{id}', [AuthController::class, 'revokeSession']);

    Route::get('home', [HomeController::class, 'show'])->middleware('perm:dashboards.view');
    Route::get('search', SearchController::class);

    // Semantic layer
    Route::middleware('perm:semantic.view,query.run')->group(function () {
        Route::get('semantic-models', [SemanticModelController::class, 'index']);
        Route::get('semantic-catalog', [SemanticModelController::class, 'catalog']);
        Route::get('semantic-models/{key}', [SemanticModelController::class, 'show']);
        Route::get('semantic-models/{key}/metrics/{metric}/lineage', [SemanticModelController::class, 'lineage']);
    });
    Route::middleware('perm:semantic.manage')->group(function () {
        Route::post('semantic-models/import', [SemanticModelController::class, 'import']);
        Route::patch('semantic-models/{key}/metrics/{metric}', [SemanticModelController::class, 'updateMetric']);
    });

    // Query & analysis
    Route::middleware('perm:query.run')->group(function () {
        Route::post('query', [QueryController::class, 'run']);
        Route::post('kpis', [QueryController::class, 'kpis']);
        Route::post('analysis/root-cause', [AnalysisController::class, 'rootCause']);
        Route::post('analysis/drill', [AnalysisController::class, 'drill']);
        Route::get('anomalies', [AnalysisController::class, 'anomalies']);
        Route::patch('anomalies/{id}', [AnalysisController::class, 'updateAnomaly']);
        Route::get('insights', [InsightController::class, 'index']);
        Route::get('insights/{id}', [InsightController::class, 'show']);
        Route::post('insights/generate', [InsightController::class, 'generate']);
    });
    Route::post('query/explain', [QueryController::class, 'explain'])->middleware('perm:query.explain');
    Route::middleware('perm:analytics.advanced')->group(function () {
        Route::post('analysis/forecast', [AnalysisController::class, 'forecast']);
        Route::get('forecasts', [AnalysisController::class, 'forecasts']);
        Route::post('analysis/scenario', [AnalysisController::class, 'scenario']);
        Route::get('scenarios', [AnalysisController::class, 'scenarios']);
        Route::post('analysis/anomalies/scan', [AnalysisController::class, 'scanAnomalies']);
    });

    // Dashboards
    Route::middleware('perm:dashboards.view')->group(function () {
        Route::get('dashboards', [DashboardController::class, 'index']);
        Route::get('dashboards/{id}', [DashboardController::class, 'show']);
        Route::post('dashboards/{id}/widgets/{widget}/data', [DashboardController::class, 'widgetData']);
    });
    Route::middleware('perm:dashboards.manage')->group(function () {
        Route::post('dashboards', [DashboardController::class, 'store']);
        Route::patch('dashboards/{id}', [DashboardController::class, 'update']);
        Route::delete('dashboards/{id}', [DashboardController::class, 'destroy']);
        Route::put('dashboards/{id}/layout', [DashboardController::class, 'saveLayout']);
        Route::post('dashboards/{id}/widgets', [DashboardController::class, 'addWidget']);
        Route::patch('dashboards/{id}/widgets/{widget}', [DashboardController::class, 'updateWidget']);
        Route::delete('dashboards/{id}/widgets/{widget}', [DashboardController::class, 'deleteWidget']);
    });

    // Reports
    Route::middleware('perm:reports.view')->group(function () {
        Route::get('report-templates', [ReportController::class, 'templates']);
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/{id}', [ReportController::class, 'show']);
        Route::get('reports/{id}/compare', [ReportController::class, 'compare']);
        Route::get('report-exports/{export}', [ReportController::class, 'exportStatus']);
    });
    Route::middleware('perm:reports.export')->group(function () {
        Route::post('reports/{id}/exports', [ReportController::class, 'export']);
        Route::get('report-exports/{export}/download', [ReportController::class, 'download']);
    });
    Route::middleware('perm:reports.manage')->group(function () {
        Route::post('report-templates', [ReportController::class, 'storeTemplate']);
        Route::post('reports', [ReportController::class, 'store']);
        Route::post('reports/generate', [ReportController::class, 'generate']);
        Route::patch('reports/{id}', [ReportController::class, 'update']);
        Route::delete('reports/{id}', [ReportController::class, 'destroy']);
        Route::post('reports/{id}/sections', [ReportController::class, 'addSection']);
        Route::patch('reports/{id}/sections/{section}', [ReportController::class, 'updateSection']);
        Route::delete('reports/{id}/sections/{section}', [ReportController::class, 'deleteSection']);
        Route::put('reports/{id}/sections/order', [ReportController::class, 'reorder']);
        Route::post('reports/{id}/refresh', [ReportController::class, 'refresh']);
        Route::post('reports/{id}/publish', [ReportController::class, 'publish']);
        Route::post('reports/{id}/archive', [ReportController::class, 'archive']);
        Route::post('reports/{id}/duplicate', [ReportController::class, 'duplicate']);
        Route::post('reports/{id}/versions', [ReportController::class, 'saveVersion']);
        Route::post('reports/{id}/versions/{version}/restore', [ReportController::class, 'restore'])->whereNumber('version');
        Route::post('reports/{id}/schedules', [ReportController::class, 'schedule']);
        Route::delete('reports/{id}/schedules/{schedule}', [ReportController::class, 'unschedule']);
    });

    // Alerts & notifications
    Route::middleware('perm:alerts.view')->group(function () {
        Route::get('alert-rules', [AlertController::class, 'rules']);
        Route::get('alerts', [AlertController::class, 'history']);
        Route::post('alerts/{id}/acknowledge', [AlertController::class, 'acknowledge']);
    });
    Route::middleware('perm:alerts.manage')->group(function () {
        Route::post('alert-rules', [AlertController::class, 'store']);
        Route::patch('alert-rules/{id}', [AlertController::class, 'update']);
        Route::delete('alert-rules/{id}', [AlertController::class, 'destroy']);
        Route::post('alert-rules/{id}/evaluate', [AlertController::class, 'evaluate']);
    });
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/stream', [NotificationController::class, 'stream']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

    // AI conversations (persistence for the AI service, which calls as the user)
    Route::middleware(['perm:ai.use', 'throttle:ai'])->group(function () {
        Route::get('ai/conversations', [AiController::class, 'conversations']);
        Route::post('ai/conversations', [AiController::class, 'createConversation']);
        Route::get('ai/conversations/{id}', [AiController::class, 'conversation']);
        Route::delete('ai/conversations/{id}', [AiController::class, 'deleteConversation']);
        Route::post('ai/runs', [AiController::class, 'recordRun']);
        Route::post('ai/runs/{id}/feedback', [AiController::class, 'feedback']);
    });

    // Data platform
    Route::middleware('perm:data.view')->group(function () {
        Route::get('connectors', [DataController::class, 'connectors']);
        Route::get('data-sources', [DataController::class, 'sources']);
        Route::get('data-sources/{id}/runs', [DataController::class, 'runs']);
        Route::get('datasets', [DataController::class, 'datasets']);
        Route::get('datasets/{id}', [DataController::class, 'dataset']);
        Route::get('datasets/{id}/preview', [DataController::class, 'preview']);
        Route::get('datasets/{id}/semantic-proposal', [DataController::class, 'proposeModel']);
    });
    Route::middleware('perm:data.manage')->group(function () {
        Route::post('data-sources', [DataController::class, 'storeSource']);
        Route::delete('data-sources/{id}', [DataController::class, 'deleteSource']);
        Route::post('data-sources/{id}/test', [DataController::class, 'test']);
        Route::post('data-sources/{id}/sync', [DataController::class, 'sync']);
        Route::post('data/upload', [DataController::class, 'upload']);
        Route::post('datasets/{id}/profile', [DataController::class, 'profile']);
        Route::post('datasets/{id}/semantic-model', [DataController::class, 'createModel'])->middleware('perm:semantic.manage');
    });

    // Governance
    Route::get('governance/audit-logs', [GovernanceController::class, 'auditLogs'])->middleware('perm:audit.view');
    Route::middleware('perm:governance.view')->group(function () {
        Route::get('governance/ai', [GovernanceController::class, 'ai']);
        Route::get('governance/ai/runs/{id}', [GovernanceController::class, 'run']);
        Route::get('governance/data-quality', [GovernanceController::class, 'dataQuality']);
    });

    // Collaboration
    Route::middleware('perm:collab.comment')->group(function () {
        Route::get('comments', [CollaborationController::class, 'comments']);
        Route::post('comments', [CollaborationController::class, 'comment']);
        Route::post('comments/{id}/resolve', [CollaborationController::class, 'resolve']);
    });
    Route::get('bookmarks', [CollaborationController::class, 'bookmarks']);
    Route::post('bookmarks/toggle', [CollaborationController::class, 'toggleBookmark']);

    // Administration
    Route::prefix('admin')->group(function () {
        Route::middleware('perm:admin.users')->group(function () {
            Route::get('users', [AdminController::class, 'users']);
            Route::post('users', [AdminController::class, 'storeUser']);
            Route::patch('users/{id}', [AdminController::class, 'updateUser']);
        });
        Route::middleware('perm:admin.roles,admin.users')->group(function () {
            Route::get('roles', [AdminController::class, 'roles']);
            Route::get('permissions', [AdminController::class, 'permissions']);
        });
        Route::post('roles', [AdminController::class, 'storeRole'])->middleware('perm:admin.roles');
        Route::middleware('perm:admin.org')->group(function () {
            Route::get('organisation', [AdminController::class, 'organisation']);
            Route::patch('organisation', [AdminController::class, 'updateOrganisation']);
            Route::get('feature-flags', [AdminController::class, 'flags']);
            Route::put('feature-flags/{key}', [AdminController::class, 'setFlag']);
        });
        Route::get('health', [AdminController::class, 'health'])->middleware('perm:admin.system');
    });
});
