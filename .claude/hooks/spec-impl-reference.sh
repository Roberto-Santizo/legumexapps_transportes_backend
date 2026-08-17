#!/usr/bin/env bash
#
# Hook UserPromptSubmit — /spec-impl => resumen de feature en references/
#
# Cuando el prompt empieza por /spec-impl:
#   1. Deja una marca de entrega pendiente en .claude/tmp/ con la spec y el
#      nombre del documento esperado (la lee el hook Stop de comprobacion).
#   2. Inyecta en el contexto del turno el contrato del documento, tomando
#      references/zones-api.md como plantilla de tono y profundidad.
#
# Si el prompt no es /spec-impl, sale en silencio sin tocar nada.

set -u

ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
MARKER_DIR="$ROOT/.claude/tmp"
MARKER="$MARKER_DIR/spec-impl-doc-pending"

payload="$(cat)"

# El JSON del hook llega por stdin. Sin jq en este entorno: php (siempre presente
# en este repo) y sed como red de seguridad.
read_prompt() {
  if command -v php >/dev/null 2>&1; then
    printf '%s' "$payload" | php -r '$i = json_decode(stream_get_contents(STDIN), true); echo is_array($i) && isset($i["prompt"]) ? $i["prompt"] : "";'
  else
    printf '%s' "$payload" | sed -n 's/.*"prompt"[[:space:]]*:[[:space:]]*"\(\([^"\\]\|\\.\)*\)".*/\1/p'
  fi
}

prompt="$(read_prompt)"

case "$prompt" in
  /spec-impl*) ;;
  *) exit 0 ;;
esac

# Primer token despues del comando: 11, pilot-salaries o 11-pilot-salaries.
arg="$(printf '%s' "$prompt" | sed -e 's#^/spec-impl[[:space:]]*##' -e 's#[[:space:]].*##' -e 's#\\.*##')"

spec_file=""
if [ -n "$arg" ]; then
  for candidate in "$ROOT/specs/$arg.md" "$ROOT/specs/$arg"*.md "$ROOT/specs/"*"$arg"*.md; do
    if [ -f "$candidate" ]; then
      spec_file="$candidate"
      break
    fi
  done
fi

if [ -n "$spec_file" ]; then
  slug="$(basename "$spec_file" .md)"
  domain="$(printf '%s' "$slug" | sed 's/^[0-9]\{1,\}-//')"
  spec_rel="specs/$slug.md"
  doc_rel="references/$domain-api.md"
else
  # Sin argumento (o argumento que no resuelve): la spec la elige el usuario en
  # la Fase 1 del skill. El hook Stop acepta cualquier .md nuevo en references/.
  spec_rel=""
  doc_rel="references/<dominio>-api.md"
fi

mkdir -p "$MARKER_DIR"
{
  printf 'spec=%s\n' "$spec_rel"
  printf 'doc=%s\n' "$doc_rel"
  printf 'attempted=0\n'
} >"$MARKER"

context="$(cat <<EOF
## Entrega obligatoria de /spec-impl: resumen de la feature en references/

Ademas de implementar la spec, este comando tiene una entrega mas, y es la
ultima: un documento de referencia de integracion para el frontend en
\`$doc_rel\`.

Cuando ocurre: despues del ultimo paso del plan y de verificar los criterios de
aceptacion, antes del commit final. No lo escribas a medias durante los pasos
intermedios.

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
- Spec de referencia: ${spec_rel:-la que resuelvas en la Fase 1}.
EOF
)"

if command -v php >/dev/null 2>&1; then
  printf '%s' "$context" | php -r '$c = stream_get_contents(STDIN); echo json_encode(["hookSpecificOutput" => ["hookEventName" => "UserPromptSubmit", "additionalContext" => $c]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);'
else
  # En UserPromptSubmit el stdout plano tambien se añade al contexto.
  printf '%s\n' "$context"
fi

exit 0
