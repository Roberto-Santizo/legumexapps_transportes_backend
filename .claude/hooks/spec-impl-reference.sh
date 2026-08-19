#!/usr/bin/env bash
#
# Hook UserPromptSubmit — /spec-impl => resumen de feature en references/
#
# Cuando el prompt empieza por /spec-impl:
#   1. Deja una marca de entrega pendiente en .claude/tmp/ con la spec y el
#      nombre del documento esperado (la lee el hook Stop de comprobacion).
#   2. Inyecta un aviso corto de aplazamiento: el documento es la ULTIMA entrega,
#      asi que aqui solo se anuncia. El contrato completo (estructura, plantilla
#      y reglas) lo entrega el hook Stop cuando la spec ya esta implementada;
#      volcarlo al inicio hacia que el resumen se escribiera antes de tiempo,
#      contra codigo aun a medias.
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
## Entrega final de /spec-impl (aviso, no la hagas todavia)

Este comando tiene una entrega mas alla de implementar la spec: un documento de
referencia de integracion para el frontend en \`$doc_rel\`. Es la **ultima**
entrega, no una de las intermedias.

Cuando escribirlo: despues del ultimo paso del plan y de verificar los criterios
de aceptacion, antes del commit final. Hasta entonces **no lo empieces, ni
siquiera en borrador**: documentarias rutas, validaciones y mensajes que todavia
van a cambiar.

El contrato completo del documento (plantilla, secciones y reglas) te llega solo
al terminar, cuando la spec ya este marcada como Implementado. Aqui basta con
que lo tengas en el plan como paso final.

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
