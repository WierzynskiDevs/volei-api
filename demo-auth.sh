#!/usr/bin/env bash
#
# Demonstração do fluxo de autenticação da Fase 1A contra a API rodando.
#
# Uso:
#   1) docker compose up -d
#   2) php artisan serve
#   3) bash demo-auth.sh
#
# O fluxo é o mesmo que o volei-app terá de seguir (ADR 0005): buscar o cookie
# CSRF antes de qualquer POST, e mandar o token no header X-XSRF-TOKEN.

set -u

BASE="${BASE:-http://127.0.0.1:8000}"
API="$BASE/api/v1"
ORIGIN="${ORIGIN:-http://localhost:3000}"
JAR="$(mktemp -t saque-cookies.XXXXXX)"
STAMP="$(date +%s)"
EMAIL="demo$STAMP@saque.app"
SENHA="senha-forte-123"
# Telefone único por execução: e-mail e telefone têm unique constraint, então
# reexecutar com valores fixos devolveria 409 em vez de demonstrar o cadastro.
TELEFONE="(48) 9${STAMP: -8}"

trap 'rm -f "$JAR"' EXIT

xsrf() { grep 'XSRF-TOKEN' "$JAR" | awk '{print $7}' | sed 's/%3D/=/g'; }

req() {
  curl -s -c "$JAR" -b "$JAR" \
    -H "Origin: $ORIGIN" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-XSRF-TOKEN: $(xsrf)" \
    "$@"
}

titulo() { printf '\n\033[1m%s\033[0m\n' "$1"; }

titulo "0. Cookie CSRF (obrigatório no modo cookie do Sanctum)"
curl -s -c "$JAR" -b "$JAR" -H "Origin: $ORIGIN" -o /dev/null \
  -w "   GET /sanctum/csrf-cookie -> HTTP %{http_code}\n" "$BASE/sanctum/csrf-cookie"

titulo "1. GET /me sem sessão  (esperado: 401 UNAUTHENTICATED)"
req "$API/me" -w "\n   [HTTP %{http_code}]\n"

titulo "2. POST /auth/register  (esperado: 201)"
req -X POST "$API/auth/register" -w "\n   [HTTP %{http_code}]\n" -d "{
  \"name\": \"Ana Ribeiro\",
  \"email\": \"$EMAIL\",
  \"password\": \"$SENHA\",
  \"password_confirmation\": \"$SENHA\",
  \"phone\": \"$TELEFONE\",
  \"accept_terms\": true,
  \"accept_privacy\": true
}"

titulo "3. Tentativa de escalada de privilégio  (role enviado deve ser IGNORADO)"
req -X POST "$API/auth/register" -w "\n   [HTTP %{http_code}]\n" -d "{
  \"name\": \"Invasor\",
  \"email\": \"invasor$(date +%s)@saque.app\",
  \"password\": \"$SENHA\",
  \"password_confirmation\": \"$SENHA\",
  \"role\": \"SUPER_ADMIN\",
  \"roles\": [\"SUPER_ADMIN\"],
  \"status\": \"BLOCKED\",
  \"accept_terms\": true,
  \"accept_privacy\": true
}"
echo '   ^ confira acima: roles deve vir ["PLAYER"] e status "ACTIVE"'

titulo "4. GET /me autenticado  (esperado: 200 com os próprios dados)"
req "$API/me" -w "\n   [HTTP %{http_code}]\n"

titulo "5. POST /auth/logout  (esperado: 204)"
req -X POST "$API/auth/logout" -w "   [HTTP %{http_code}]\n"

titulo "6. GET /me depois do logout  (esperado: 401 — sessão morta no servidor)"
req "$API/me" -w "\n   [HTTP %{http_code}]\n"

titulo "7. Login com senha errada  (esperado: 401 INVALID_CREDENTIALS)"
req -X POST "$API/auth/login" -w "\n   [HTTP %{http_code}]\n" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"errada\"}"

titulo "8. Login com e-mail inexistente  (resposta DEVE ser idêntica à de cima)"
req -X POST "$API/auth/login" -w "\n   [HTTP %{http_code}]\n" \
  -d '{"email":"ninguem@saque.app","password":"errada"}'
echo '   ^ mesma mensagem e mesmo status: o login não revela se a conta existe'

titulo "9. Rate limiting  (esperado: 429 a partir da 4ª tentativa)"
for i in 1 2 3 4 5 6; do
  code=$(req -o /dev/null -w "%{http_code}" -X POST "$API/auth/login" \
    -d "{\"email\":\"$EMAIL\",\"password\":\"errada\"}")
  echo "   tentativa $i -> HTTP $code"
done

titulo "10. Trilha de auditoria gravada no banco"
docker exec saque-postgres psql -U saque -d saque -c \
  "SELECT action, actor_role, target_type, ip FROM audit_logs ORDER BY created_at DESC LIMIT 8;"

titulo "11. audit_logs é append-only  (UPDATE e DELETE devem ser REJEITADOS)"
docker exec saque-postgres psql -U saque -d saque -c \
  "UPDATE audit_logs SET action='ADULTERADO';" 2>&1 | head -2
docker exec saque-postgres psql -U saque -d saque -c \
  "DELETE FROM audit_logs;" 2>&1 | head -2

printf '\n\033[1mFim.\033[0m O login correto exige esperar o rate limit expirar (1 min).\n'
