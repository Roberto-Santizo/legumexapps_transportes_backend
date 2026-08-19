#!/usr/bin/env bash
#
# Hook Stop — entrega final del resumen de feature en references/
#
# Este hook, y no el de UserPromptSubmit, es el que dicta el contrato completo
# del documento: llega cuando la implementacion ya esta cerrada, que es cuando
# rutas, FormRequests, Resources y mensajes de error ya no van a cambiar.
#
# Solo actua si se cumplen las tres condiciones:
#   1. Hay una marca de entrega pendiente dejada por el hook UserPromptSubmit.
#   2. La spec ya quedo cerrada: su linea de estado dice Implementado/Implemented.
#      Durante las pausas paso a paso de /spec-impl sigue en Aprobado, asi que el
#      hook no interrumpe el ritmo de revision de diffs.
#   3. El documento de references/ no existe.
#
# Bloquea una sola vez (attempted=1) y despues se limpia solo: si el resumen
# sigue sin aparecer, avisa y suelta en vez de ciclar.

set -u

ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
MARKER="$ROOT/.claude/tmp/spec-impl-doc-pending"

[ -f "$MARKER" ] || exit 0

spec_rel="$(sed -n 's/^spec=//p' "$MARKER" | head -n 1)"
doc_rel="$(sed -n 's/^doc=//p' "$MARKER" | head -n 1)"
attempted="$(sed -n 's/^attempted=//p' "$MARKER" | head -n 1)"

emit_json() {
  # $1 = clave, $2 = valor. Sin jq: php escapa, con un fallback minimo.
  if command -v php >/dev/null 2>&1; then
    printf '%s' "$2" | php -r '$v = stream_get_contents(STDIN); $k = $argv[1]; $out = $k === "block" ? ["decision" => "block", "reason" => $v] : ["systemMessage" => $v]; echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "$1"
  else
    escaped="$(printf '%s' "$2" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | awk '{printf "%s\\n", $0}')"
    if [ "$1" = "block" ]; then
      printf '{"decision":"block","reason":"%s"}' "$escaped"
    else
      printf '{"systemMessage":"%s"}' "$escaped"
    fi
  fi
}

# Sin spec resuelta (se invoco /spec-impl sin argumento) no hay linea de estado
# que consultar: basta con que aparezca cualquier .md nuevo en references/.
if [ -n "$spec_rel" ]; then
  [ -f "$ROOT/$spec_rel" ] || exit 0
  if ! head -n 15 "$ROOT/$spec_rel" | grep -qiE '(estado|status)[^:]*:[^A-Za-z]*(implementad|implemented)'; then
    exit 0
  fi
fi

if [ -f "$ROOT/$doc_rel" ]; then
  rm -f "$MARKER"
  exit 0
fi

# Un nombre distinto elegido a conciencia tambien cuenta como entregado.
if [ -d "$ROOT/references" ]; then
  newer="$(find "$ROOT/references" -maxdepth 1 -name '*.md' -newer "$MARKER" -print 2>/dev/null | head -n 1)"
  if [ -n "$newer" ]; then
    rm -f "$MARKER"
    exit 0
  fi
fi

if [ "$attempted" = "1" ]; then
  rm -f "$MARKER"
  emit_json message "Aviso: /spec-impl termino sin el resumen de la feature en $doc_rel. Se deja de insistir; generalo cuando quieras pidiendolo a mano."
  exit 0
fi

sed -i 's/^attempted=0$/attempted=1/' "$MARKER"

emit_json block "$(cat <<EOF
Falta la ultima entrega de /spec-impl: el resumen de la feature en \`$doc_rel\`.

La spec ${spec_rel:-implementada} ya esta cerrada, asi que este es el momento de
escribirlo: el codigo ya no va a cambiar. Documento de referencia de integracion
para el frontend, ahora si.

Plantilla: \`references/zones-api.md\` (y \`references/freight-rates-api.md\`).
Copia su estructura, su tono y su nivel de detalle:

1. Titulo + intro: que dominio es, cuantos endpoints, bajo que prefijo.
2. "Lo minimo que hay que saber antes de escribir codigo": las trampas reales
   del dominio numeradas, no generalidades.
3. Autenticacion y permisos: tabla accion x rol (administrator, carrier, pilot,
   manager) y si aplica \`carrier.required\`.
4. El objeto del recurso: JSON de ejemplo + tabla campo/tipo/notas + tipos
   TypeScript sugeridos.
5. Formato de las respuestas: sobre \`{ statusCode, message, data }\`, listado
   sin paginar, listado paginado (metadatos en la raiz) y el 422 de Laravel
   \`{ message, errors }\`, que es un formato distinto.
6. Endpoints uno por uno: query params, body, reglas por campo, codigos de
   respuesta y mensajes de exito literales.
7. Tabla de mensajes de error literales, copiados de los \`messages()\` de los
   FormRequests y de los errores de \`App\Errors\` del service.
8. Checklist de implementacion en el frontend.
9. "Lo que este dominio no hace", para que el front no lo diseñe.

Reglas del documento:

- Todo verificado contra el codigo real de esta rama: rutas, FormRequests,
  Resources, Service y tests. Si no lo has comprobado, no lo escribas.
- Los mensajes de error van literales, en español, listos para mostrarse.
- Salida en camelCase y fechas en \`d-m-Y h:i:s A\` (no ISO 8601): dilo explicito.
- Prosa en español; nombres de codigo en ingles.

Si el commit final ya esta hecho, incluye el documento en un commit aparte.
EOF
)"

exit 0
