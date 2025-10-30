#!/bin/bash
# unpause_ramal.sh
# Despausa um ramal em TODAS as filas que ele participa no Asterisk

ramal="$1"

if [[ -z "$ramal" ]]; then
  echo "Uso: $0 <ramal>"
  exit 1
fi

# Detecta o tipo de tecnologia (PJSIP ou SIP)
tech_type=$(asterisk -rx "queue show" | grep -oE "(PJSIP|SIP)/$ramal" | head -n1 | cut -d'/' -f1)

if [[ -z "$tech_type" ]]; then
  echo "Erro: Ramal $ramal não encontrado em nenhuma fila."
  exit 1
fi

# Verifica se está pausado
pausado=$(asterisk -rx "queue show" | grep -E "$tech_type/$ramal" | grep -i "paused:")

if [[ -z "$pausado" ]]; then
  echo "Ramal $ramal não está pausado em nenhuma fila."
  exit 0
fi

echo "Despausando ramal $ramal ($tech_type)..."
echo ""

# Identifica APENAS as filas onde o ramal está cadastrado
filas_do_ramal=$(asterisk -rx "queue show" | grep -B 2 "$tech_type/$ramal" | grep "^[0-9]* has [0-9]* calls" | awk '{print $1}' | sort -u)

if [[ -z "$filas_do_ramal" ]]; then
  echo "Erro: Não foi possível identificar as filas do ramal."
  exit 1
fi

count_success=0
count_fail=0

for fila in $filas_do_ramal; do
  result=$(asterisk -rx "queue unpause member $tech_type/$ramal queue $fila" 2>&1)
  
  if echo "$result" | grep -qi "unpaused interface"; then
    echo "✓ Despausado na fila: $fila"
    count_success=$((count_success + 1))
  else
    echo "✗ Erro ao despausar na fila: $fila"
    count_fail=$((count_fail + 1))
  fi
done

echo ""
echo "=========================================="
if [[ $count_fail -eq 0 ]]; then
  echo "✓ Sucesso total! Ramal $ramal despausado em $count_success fila(s)."
else
  echo "⚠ Despausado em $count_success de $((count_success + count_fail)) fila(s)."
  echo "  ($count_fail fila(s) com erro)"
fi
echo "=========================================="

# Verifica se realmente foi despausado
sleep 1
ainda_pausado=$(asterisk -rx "queue show" | grep -E "$tech_type/$ramal" | grep -i "paused:")

if [[ -n "$ainda_pausado" ]]; then
  echo ""
  echo "⚠ ATENÇÃO: Ramal ainda aparece pausado em alguma(s) fila(s)!"
fi