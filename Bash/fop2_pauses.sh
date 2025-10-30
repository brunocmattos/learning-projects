#!/bin/bash
# fop2_paused.sh
# Lista ramais pausados no Asterisk/FOP2 em formato CSV
# Uso:
#   fop2_paused.sh [--no-header] [--ramal <ext>] [--pausa <nome>]

NO_HEADER=0
FILTER_RAMAL=""
FILTER_PAUSA=""

# --- leitura de parâmetros ---
while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-header)
      NO_HEADER=1
      shift
      ;;
    --ramal)
      FILTER_RAMAL="$2"
      shift 2
      ;;
    --pausa)
      FILTER_PAUSA="$2"
      shift 2
      ;;
    *)
      echo "Uso: $0 [--no-header] [--ramal <ext>] [--pausa <nome>]"
      exit 1
      ;;
  esac
done

# --- cabeçalho ---
if [[ $NO_HEADER -eq 0 ]]; then
  echo "ramal;nome;pausa;duração"
fi

# --- coleta de pausas ---
asterisk -rx "queue show" | grep -i "(paused:" | while IFS= read -r line; do
  # Extrai ramal (número após PJSIP/ ou SIP/ ou Local/)
  ext=$(echo "$line" | grep -oE '(PJSIP|SIP|Local)/[0-9]+' | grep -oE '[0-9]+' | head -n1)
  
  # Extrai nome (primeiro campo, removendo espaços)
  nome=$(echo "$line" | awk '{print $1}' | tr -d ' ')
  
  # Extrai pausa (texto entre "paused:" e " was")
  pausa=$(echo "$line" | sed -n 's/.*paused:\([^)]*\) was.*/\1/p' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  
  # Extrai segundos - APENAS o primeiro "was X secs ago" (ignora o segundo "login was")
  secs=$(echo "$line" | sed -n 's/.*paused:[^)]*was \([0-9]\+\) secs ago.*/\1/p')
  
  # Se não encontrou segundos, pula
  [[ -z "$secs" ]] && continue
  
  # Formata duração em horas:minutos
  horas=$((secs/3600))
  mins=$(((secs%3600)/60))
  
  if [[ $horas -gt 0 ]]; then
    duracao=$(printf "%d:%02d" $horas $mins)
  else
    duracao=$(printf "%d:%02d" $mins $((secs%60)))
  fi

  # filtros opcionais
  [[ -n "$FILTER_RAMAL" && "$ext" != "$FILTER_RAMAL" ]] && continue
  [[ -n "$FILTER_PAUSA" && "$pausa" != "$FILTER_PAUSA" ]] && continue

  echo "$ext;$nome;$pausa;$duracao"
done | sort -u