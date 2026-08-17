#!/usr/bin/env bash
#
# Hook Stop — red de seguridad del resumen de feature en references/
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

La spec ${spec_rel:-implementada} ya esta cerrada pero el documento de
referencia para el frontend no existe. Escribelo ahora siguiendo la estructura
de \`references/zones-api.md\`: intro, trampas del dominio, permisos por rol,
el objeto del recurso con tipos TypeScript, formatos de respuesta (sobre y 422),
endpoint por endpoint, tabla de mensajes de error literales, checklist de
frontend y "lo que este dominio no hace".

Todo verificado contra el codigo real de esta rama (rutas, FormRequests,
Resources, Service y tests), mensajes de error literales en español, salida en
camelCase y fechas en \`d-m-Y h:i:s A\`.
EOF
)"

exit 0
