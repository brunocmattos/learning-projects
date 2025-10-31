#!/bin/bash
# fop2_pauses.sh
# Lista ramais pausados no Asterisk/FOP2 em formato CSV

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
  echo "ramal;nome;pausa;duração;segundos"
fi

# --- coleta de pausas ---
asterisk -rx "queue show" | grep -iE "\(paused:.+was [0-9]+ secs ago\)" | while IFS= read -r line; do
  # Extrai ramal
  ext=$(echo "$line" | grep -oE '(PJSIP|SIP|Local)/[0-9]+' | grep -oE '[0-9]+' | head -n1)
  
  # Extrai nome
  nome=$(echo "$line" | awk '{print $1}' | tr -d ' ')
  
  # Extrai pausa
  pausa=$(echo "$line" | sed -n 's/.*paused:\([^)]*\) was.*/\1/p' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  
  # Extrai segundos DIRETO (valor original do Asterisk)
  secs=$(echo "$line" | sed -n 's/.*paused:[^)]*was \([0-9]\+\) secs ago.*/\1/p')
  
  # Se não encontrou segundos, pula
  [[ -z "$secs" ]] && continue
  
  # Formata duração legível (HH:MM ou MM:SS dependendo do valor)
  horas=$((secs/3600))
  mins=$(((secs%3600)/60))
  segs=$((secs%60))
  
  if [[ $horas -gt 0 ]]; then
    duracao=$(printf "%d:%02d:%02d" $horas $mins $segs)
  else
    duracao=$(printf "%d:%02d" $mins $segs)
  fi

  # Filtros opcionais
  [[ -n "$FILTER_RAMAL" && "$ext" != "$FILTER_RAMAL" ]] && continue
  [[ -n "$FILTER_PAUSA" && "$pausa" != "$FILTER_PAUSA" ]] && continue

  # Output: ramal;nome;pausa;duração_legível;segundos_totais
  echo "$ext;$nome;$pausa;$duracao;$secs"
done | sort -u