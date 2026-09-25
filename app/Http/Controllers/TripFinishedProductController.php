<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripFinishedProduct\IndexTripFinishedProductRequest;
use App\Http\Requests\TripFinishedProduct\StoreTripFinishedProductRequest;
use App\Http\Requests\TripFinishedProduct\UpdateTripFinishedProductRequest;
use App\Http\Resources\TripFinishedProduct\TripFinishedProductResource;
use App\Interfaces\TripFinishedProduct\TripFinishedProductServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Finished Products',
    description: 'Productos terminados del viaje (SPEC 37): cuántas cajas de cada producto terminado del cliente lleva un viaje. Rutas SIN ANIDAR bajo /api/trip-finished-products: el viaje viaja en el query param obligatorio tripId del listado y en el body del alta. Las cuatro exigen token JWT (401 «El token de sesión no es válido o ha expirado»). LECTURA con jwt.auth a secas y el ámbito del detalle del viaje (incluido el pilot asignado, user y shipment; el carrier solo dentro de su empresa). ESCRITURA (alta, edición de cajas y borrado físico) solo administrator y export; el resto, 403 «No tienes permisos para acceder a este recurso». Toda escritura exige el viaje en pending. Un viaje siempre conserva al menos una línea.',
)]
class TripFinishedProductController extends Controller
{
    #[OA\Get(
        path: '/api/trip-finished-products',
        operationId: 'indexTripFinishedProducts',
        summary: 'Listar los productos terminados de un viaje',
        description: <<<'TEXT'
        Devuelve TODAS las líneas del viaje en orden id ASC. NUNCA PAGINA: limit y page se ignoran y el sobre no trae total, currentPage ni lastPage.
        tripId es OBLIGATORIO (sin él 422, formato de Laravel). Un viaje inexistente O BORRADO es 404 «El viaje no existe» desde el service, no 422.
        Ámbito del detalle del viaje: administrator, manager, export, user y shipment ven cualquier viaje; el pilot solo el suyo (403 «No puedes acceder a un viaje que no tienes asignado»); el carrier la bolsa y lo de su empresa (403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista»).
        Un viaje anterior a SPEC 37 no tiene líneas: 200 con data vacío.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Finished Products'],
        parameters: [
            new OA\Parameter(
                name: 'tripId',
                description: 'Id del viaje (trips.id). Obligatorio y entero (422 «El viaje es obligatorio» / «El viaje debe ser un número entero»).',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Productos del viaje obtenidos correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Productos del viaje obtenidos correctamente'),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripFinishedProduct')),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'El viaje está fuera del ámbito del usuario (pilot no asignado o carrier de otra empresa).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'El viaje no existe o fue borrado. Mensaje: El viaje no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Falta tripId o no es entero.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function index(IndexTripFinishedProductRequest $request, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $lines = $tripFinishedProductService->getTripFinishedProducts(auth('api')->user(), (int) $request->validated('tripId'));

            return ResponseHandler::success(TripFinishedProductResource::collection($lines), 'Productos del viaje obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/trip-finished-products',
        operationId: 'storeTripFinishedProduct',
        summary: 'Agregar un producto terminado a un viaje',
        description: <<<'TEXT'
        Añade una línea a un viaje existente. Solo administrator y export; el resto, 403. registeredBy sale del usuario autenticado.
        Tras validar, cinco guardas del service EN ESTE ORDEN, cada una con 400: «El viaje ya fue eliminado» → «Solo se pueden modificar los productos de un viaje pendiente» → «El producto terminado seleccionado ya fue eliminado» → «El producto terminado no pertenece al cliente del viaje» → «El producto terminado ya está en el viaje».
        Un tripId o finishedProductId que no existe en absoluto lo corta antes el FormRequest con 422.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreTripFinishedProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Finished Products'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Producto agregado al viaje correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto agregado al viaje correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripFinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'Una de las cinco guardas, en orden: El viaje ya fue eliminado / Solo se pueden modificar los productos de un viaje pendiente / El producto terminado seleccionado ya fue eliminado / El producto terminado no pertenece al cliente del viaje / El producto terminado ya está en el viaje', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Campo ausente, inexistente o boxes fuera de [1, 999999] o no entero.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreTripFinishedProductRequest $request, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $created = $tripFinishedProductService->createTripFinishedProduct($request->validated(), auth('api')->user());

            return ResponseHandler::success(new TripFinishedProductResource($created), 'Producto agregado al viaje correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/trip-finished-products/{tripFinishedProduct}',
        operationId: 'updateTripFinishedProduct',
        summary: 'Cambiar las cajas de una línea',
        description: <<<'TEXT'
        Cambia SOLO boxes. tripId y finishedProductId se ignoran en silencio: cambiar de producto es borrar la línea y crear otra. registeredBy no se reescribe. Solo administrator y export.
        Cuerpo vacío es 422 (boxes obligatorio). Orden de fallo: 404 «La línea de producto no existe» → 400 «El viaje ya fue eliminado» → 400 «Solo se pueden modificar los productos de un viaje pendiente».
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateTripFinishedProductRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Trip Finished Products'],
        parameters: [
            new OA\Parameter(name: 'tripFinishedProduct', description: 'Id de la línea (trip_finished_products.id).', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto del viaje actualizado correctamente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto del viaje actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripFinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'El viaje ya fue eliminado / Solo se pueden modificar los productos de un viaje pendiente', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'La línea no existe. Mensaje: La línea de producto no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'boxes ausente, no entero o fuera de [1, 999999].', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateTripFinishedProductRequest $request, int $tripFinishedProduct, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $updated = $tripFinishedProductService->updateTripFinishedProduct($tripFinishedProduct, $request->validated());

            return ResponseHandler::success(new TripFinishedProductResource($updated), 'Producto del viaje actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/trip-finished-products/{tripFinishedProduct}',
        operationId: 'destroyTripFinishedProduct',
        summary: 'Quitar un producto terminado de un viaje',
        description: <<<'TEXT'
        BORRADO FÍSICO: la fila desaparece y no hay restore. Solo administrator y export.
        Orden de fallo: 404 «La línea de producto no existe» → 400 «El viaje ya fue eliminado» → 400 «Solo se pueden modificar los productos de un viaje pendiente» → 400 «El viaje debe tener al menos un producto terminado» (no se puede borrar la última línea).
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Finished Products'],
        parameters: [
            new OA\Parameter(name: 'tripFinishedProduct', description: 'Id de la línea (trip_finished_products.id).', in: 'path', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Producto eliminado del viaje correctamente. data trae la línea recién borrada.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Producto eliminado del viaje correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/TripFinishedProduct'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 400, description: 'El viaje ya fue eliminado / Solo se pueden modificar los productos de un viaje pendiente / El viaje debe tener al menos un producto terminado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'El rol no es administrator ni export. Mensaje: No tienes permisos para acceder a este recurso', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'La línea no existe. Mensaje: La línea de producto no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
        ],
    )]
    public function destroy(int $tripFinishedProduct, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $deleted = $tripFinishedProductService->deleteTripFinishedProduct($tripFinishedProduct);

            return ResponseHandler::success(new TripFinishedProductResource($deleted), 'Producto eliminado del viaje correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
