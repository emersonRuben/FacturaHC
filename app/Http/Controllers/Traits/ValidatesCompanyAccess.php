<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ValidatesCompanyAccess
{
    /**
     * Valida que el modelo pertenece a la empresa del usuario autenticado
     * 
     * @param mixed $model El modelo a validar (debe tener company_id)
     * @param Request $request
     * @return JsonResponse|null Retorna JsonResponse con error 403 si no tiene acceso, null si tiene acceso
     */
    protected function validateCompanyAccess($model, Request $request): ?JsonResponse
    {
        $user = $request->user();
        
        // Super admin puede acceder a todo
        if ($user->role && $user->role->name === 'super_admin') {
            return null;
        }

        // Validar que el usuario tenga empresa asignada
        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario sin empresa asignada. Contacte al administrador.'
            ], 403);
        }

        // Validar que el modelo tenga el atributo company_id
        if (!isset($model->company_id)) {
            return response()->json([
                'success' => false,
                'message' => 'El recurso no tiene información de empresa'
            ], 500);
        }

        // Validar que la empresa del modelo coincide con la del usuario
        if ($model->company_id !== $user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para acceder a este recurso'
            ], 403);
        }

        return null;
    }

    /**
     * Valida y retorna el modelo si el usuario tiene acceso
     * Si no tiene acceso, retorna una respuesta de error
     * 
     * @param mixed $model
     * @param Request $request
     * @return mixed El modelo si tiene acceso, JsonResponse si no
     */
    protected function authorizeCompanyAccess($model, Request $request)
    {
        $accessError = $this->validateCompanyAccess($model, $request);
        
        if ($accessError) {
            return $accessError;
        }

        return $model;
    }
}
