<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    title: 'Registro de usuario',
    description: <<<'TEXT'
    Datos para crear una cuenta. Solo se permite registrar los roles pilot y carrier.

    ATENCIÓN — CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA: el registro de un piloto pasa de cuatro campos a seis. Desde esta versión role=pilot exige además dpi y license, las fotos del anverso del DPI y de la licencia. Un alta de piloto que antes devolvía 201 con cuatro campos ahora devuelve 422.

    ATENCIÓN — EL CUERPO YA NO VA EN JSON CUANDO SE REGISTRA UN PILOTO, VA EN multipart/form-data. Un archivo no viaja en un cuerpo JSON. Es la primera ruta pública del proyecto que recibe archivos: no lleva token y aun así sube al bucket.

    LOS DOS O NINGUNO: registrarse como pilot mandando solo dpi o solo license devuelve 422. No existe el alta a medias, porque tampoco existe un endpoint para completarla después.

    CUIDADO — UN carrier QUE MANDE dpi Y license LOS VE DESCARTADOS EN SILENCIO: la respuesta es 201, no 422, no se sube ningún archivo, no se crea ninguna fila de documentos y dpiImage y licenseImage vuelven en null. Mismo comportamiento que la factura de un gasto con is_invoiced en falso.

    Las fotos se suben UNA SOLA VEZ, en el alta, y NO SE PUEDEN REEMPLAZAR: no hay PATCH, no hay endpoint nuevo y ninguna ruta existente las acepta. Corregir una foto mal subida se hace fuera de la aplicación.
    TEXT,
    required: ['name', 'email', 'password', 'password_confirmation', 'role'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Roberto Santizo'),
        new OA\Property(
            property: 'email',
            description: 'Debe ser único: no puede existir otra cuenta con el mismo correo.',
            type: 'string',
            format: 'email',
            maxLength: 255,
            example: 'piloto@legumex.com',
        ),
        new OA\Property(
            property: 'password',
            description: 'Mínimo 8 caracteres. Debe coincidir con password_confirmation.',
            type: 'string',
            format: 'password',
            minLength: 8,
            example: 'secret123',
        ),
        new OA\Property(
            property: 'password_confirmation',
            description: 'Repetición exacta de password.',
            type: 'string',
            format: 'password',
            minLength: 8,
            example: 'secret123',
        ),
        new OA\Property(
            property: 'role',
            description: 'Rol solicitado. Los roles administrator y manager no pueden autoregistrarse.',
            type: 'string',
            enum: ['pilot', 'carrier'],
            example: 'pilot',
        ),
        new OA\Property(
            property: 'dpi',
            description: 'Foto del ANVERSO del DPI: jpg, jpeg o png, de 3 MB (3072 KB) como máximo. OBLIGATORIA SOLO CUANDO role ES pilot; omitirla en ese caso devuelve 422 con el mensaje La foto del DPI es obligatoria para los pilotos. Un archivo que no sea imagen —un PDF renombrado a .jpg, por ejemplo— devuelve La foto del DPI debe ser una imagen, un tipo no permitido devuelve La foto del DPI debe ser un archivo jpg, jpeg o png y pasarse de tamaño devuelve La foto del DPI no puede superar los 3 MB. VA SIEMPRE ACOMPAÑADA DE license: mandar solo una de las dos es 422. Solo el anverso: el reverso no se pide ni se guarda. NO se recorta, no se redimensiona y no se recomprime: el archivo queda en el bucket tal cual se sube. Requiere upload_max_filesize y post_max_size de 4M o más en el servidor; si PHP corta antes, el error que llega es un required confuso y no uno de tamaño.',
            type: 'string',
            format: 'binary',
        ),
        new OA\Property(
            property: 'license',
            description: 'Foto del ANVERSO de la licencia de conducir, con las mismas reglas que dpi: jpg, jpeg o png, 3 MB como máximo y obligatoria solo cuando role es pilot. Omitirla devuelve 422 con el mensaje La foto de la licencia es obligatoria para los pilotos. NO se guarda ningún otro dato de la licencia: ni número, ni tipo (A, B, C), ni fecha de emisión, ni FECHA DE VENCIMIENTO. La API no sabe si una licencia está caducada y no valida que la foto sea legible ni que sea realmente una licencia.',
            type: 'string',
            format: 'binary',
        ),
    ],
    type: 'object',
)]
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([UserRole::Pilot->value, UserRole::Carrier->value])],
            /**
             * The project's first conditional validation by role. `image` goes on top of
             * `mimes` on purpose: `mimes` looks at the real content type, while `image`
             * rejects a renamed PDF up front.
             */
            'dpi' => ['required_if:role,'.UserRole::Pilot->value, 'image', 'mimes:jpg,jpeg,png', 'max:3072'],
            'license' => ['required_if:role,'.UserRole::Pilot->value, 'image', 'mimes:jpg,jpeg,png', 'max:3072'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio',
            'name.string' => 'El nombre debe ser texto',
            'name.max' => 'El nombre no puede superar los 255 caracteres',
            'email.required' => 'El correo es obligatorio',
            'email.email' => 'El correo no tiene un formato válido',
            'email.max' => 'El correo no puede superar los 255 caracteres',
            'email.unique' => 'El correo ya está registrado',
            'password.required' => 'La contraseña es obligatoria',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'password.confirmed' => 'La confirmación de la contraseña no coincide',
            'role.required' => 'El rol es obligatorio',
            'role.in' => 'El rol debe ser piloto o transportista',
            'dpi.required_if' => 'La foto del DPI es obligatoria para los pilotos',
            'dpi.image' => 'La foto del DPI debe ser una imagen',
            'dpi.mimes' => 'La foto del DPI debe ser un archivo jpg, jpeg o png',
            'dpi.max' => 'La foto del DPI no puede superar los 3 MB',
            'license.required_if' => 'La foto de la licencia es obligatoria para los pilotos',
            'license.image' => 'La licencia debe ser una imagen',
            'license.mimes' => 'La licencia debe ser un archivo jpg, jpeg o png',
            'license.max' => 'La foto de la licencia no puede superar los 3 MB',
        ];
    }
}
