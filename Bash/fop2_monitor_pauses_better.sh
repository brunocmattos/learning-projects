#!/bin/bash
# fop2_monitor_pauses.sh
# Monitora pausas em tempo real e executa ações baseadas em configuração

SCRIPT_DIR="/etc/guarida/scripts/fop2PauseEngine"
CONFIG_FILE="/etc/guarida/scripts/fop2PauseEngine/config/pause_config.conf"
SUPERVISORES_FILE="/etc/guarida/scripts/fop2PauseEngine/supervisores.conf"
LOG_FILE="/var/log/guarida/pause_monitor.log"
CSV_LOG="/var/log/guarida/pause_actions.csv"
NOTIFICATION_CACHE="/var/run/fop2_notification_cache.txt"

# Intervalo de verificação em segundos
CHECK_INTERVAL=10

# Timeout SSH em segundos
SSH_TIMEOUT=5

# Tempo máximo de cache em segundos (24 horas)
CACHE_MAX_AGE=86400

# Caminho do script PowerShell no Windows
WINDOWS_SCRIPT="C:\\Scripts\\fop2_toast.ps1"

# Função de log tradicional
log_msg() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Função de log CSV estruturado
log_csv() {
  local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
  local ramal="$1"
  local nome="$2"
  local pausa="$3"
  local tempo="$4"
  local limite="$5"
  local acao="$6"
  local filas="$7"
  
  # Cria cabeçalho se arquivo não existir
  if [[ ! -f "$CSV_LOG" ]]; then
    echo "timestamp;ramal;nome;pausa;tempo_atual;tempo_limite;acao;filas" > "$CSV_LOG"
  fi
  
  echo "${timestamp};${ramal};${nome};${pausa};${tempo};${limite};${acao};${filas}" >> "$CSV_LOG"
}

# Função para limpar cache antigo
clean_old_cache() {
  if [[ ! -f "$NOTIFICATION_CACHE" ]]; then
    return
  fi
  
  local now=$(date +%s)
  local temp_file="${NOTIFICATION_CACHE}.tmp"
  
  # Lê cache e mantém apenas entradas recentes
  while IFS=':' read -r key timestamp; do
    local age=$((now - timestamp))
    if [[ $age -lt $CACHE_MAX_AGE ]]; then
      echo "${key}:${timestamp}" >> "$temp_file"
    fi
  done < "$NOTIFICATION_CACHE"
  
  # Substitui cache antigo
  if [[ -f "$temp_file" ]]; then
    mv "$temp_file" "$NOTIFICATION_CACHE"
  else
    rm -f "$NOTIFICATION_CACHE"
  fi
}

# Função para converter duração "HH:MM" ou "MM:SS" para minutos
duracao_para_minutos() {
  local duracao="$1"
  local parte1 parte2
  
  IFS=':' read -r parte1 parte2 <<< "$duracao"
  
  # Se parte1 >= 60, já está em minutos (ex: 120:30 = 120min)
  # Se parte1 < 60, é MM:SS (minutos:segundos) - considera apenas minutos
  
  if [[ $parte1 -ge 60 ]]; then
    echo "$parte1"
  else
    # Arredonda para cima se segundos >= 30
    if [[ $parte2 -ge 30 ]]; then
      echo $((parte1 + 1))
    else
      echo "$parte1"
    fi
  fi
}

# Função para verificar se deve notificar (throttling)
should_notify() {
  local ramal="$1"
  local pausa="$2"
  local intervalo_minutos="$3"
  
  # Se intervalo for 0, sempre notifica
  [[ $intervalo_minutos -eq 0 ]] && return 0
  
  local cache_key="${ramal}_${pausa}"
  local now=$(date +%s)
  local intervalo_segundos=$((intervalo_minutos * 60))
  
  # Cria arquivo de cache se não existir
  touch "$NOTIFICATION_CACHE"
  
  # Busca última notificação (otimizado com grep)
  local last_notification=$(grep "^${cache_key}:" "$NOTIFICATION_CACHE" 2>/dev/null | cut -d':' -f2)
  
  if [[ -z "$last_notification" ]]; then
    # Nunca notificou, pode notificar
    echo "${cache_key}:${now}" >> "$NOTIFICATION_CACHE"
    return 0
  fi
  
  local elapsed=$((now - last_notification))
  
  if [[ $elapsed -ge $intervalo_segundos ]]; then
    # Passou do intervalo, pode notificar
    # Usa sed inline para performance
    sed -i "/^${cache_key}:/d" "$NOTIFICATION_CACHE"
    echo "${cache_key}:${now}" >> "$NOTIFICATION_CACHE"
    return 0
  fi
  
  # Ainda no intervalo de throttling
  return 1
}

# Função para enviar notificação Toast para supervisores
send_toast_notification() {
  local ramal="$1"
  local nome="$2"
  local pausa="$3"
  local acao="$4"
  local tempo="${5:-}"
  local limite="${6:-}"
  
  if [[ ! -f "$SUPERVISORES_FILE" ]]; then
    log_msg "⚠ Arquivo de supervisores não encontrado: $SUPERVISORES_FILE"
    return 1
  fi
  
  local supervisor_count=0
  local notified_count=0
  
  while IFS='|' read -r nome_sup usuario_win ips chave_ssh; do
    [[ "$nome_sup" =~ ^#.*$ ]] || [[ -z "$nome_sup" ]] && continue
    
    nome_sup=$(echo "$nome_sup" | xargs)
    usuario_win=$(echo "$usuario_win" | xargs)
    ips=$(echo "$ips" | xargs)
    chave_ssh=$(echo "$chave_ssh" | xargs)
    
    supervisor_count=$((supervisor_count + 1))
    
    if [[ ! -f "$chave_ssh" ]]; then
      log_msg "✗ Chave SSH não encontrada para $nome_sup: $chave_ssh"
      continue
    fi
    
    # Tenta cada IP até conseguir
    local success=0
    IFS=',' read -ra IP_ARRAY <<< "$ips"
    
    for ip in "${IP_ARRAY[@]}"; do
      ip=$(echo "$ip" | xargs)
      
      local ps_command=""
      if [[ "$acao" == "DESPAUSADO" ]]; then
        ps_command="powershell.exe -ExecutionPolicy Bypass -File \"$WINDOWS_SCRIPT\" -Ramal \"$ramal\" -Nome \"$nome\" -Pausa \"$pausa\" -Acao \"$acao\""
      else
        ps_command="powershell.exe -ExecutionPolicy Bypass -File \"$WINDOWS_SCRIPT\" -Ramal \"$ramal\" -Nome \"$nome\" -Pausa \"$pausa\" -Acao \"$acao\" -Tempo \"$tempo\" -Limite \"$limite\""
      fi
      
      # Envia notificação via SSH (síncrono para garantir execução)
      log_msg "→ Tentando notificar $nome_sup via $ip..."
      if timeout $SSH_TIMEOUT ssh -i "$chave_ssh" -o StrictHostKeyChecking=no -o ConnectTimeout=$SSH_TIMEOUT -o BatchMode=yes "$usuario_win@$ip" "$ps_command" >> "$LOG_FILE" 2>&1; then
        log_msg "✓ Notificação enviada para $nome_sup ($ip)"
        success=1
        notified_count=$((notified_count + 1))
        break
      else
        log_msg "✗ Falha ao conectar em $ip (timeout ou SSH error)"
      fi
    done
    
    if [[ $success -eq 0 ]]; then
      log_msg "✗ Falha ao notificar $nome_sup em todos os IPs: $ips"
    fi
    
    if [[ $success -eq 0 ]]; then
      log_msg "✗ Falha ao notificar $nome_sup (testados: $ips)"
    fi
    
  done < "$SUPERVISORES_FILE"
  
  if [[ $supervisor_count -gt 0 ]]; then
    log_msg "📢 Notificações: $notified_count/$supervisor_count supervisores"
  fi
}

# Função para obter filas onde o ramal foi despausado
get_filas_despausadas() {
  local ramal="$1"
  local tech_type="$2"
  
  # Extrai filas do output do fop2_unpause.sh do log
  # Como não temos acesso direto, vamos pegar do queue show
  local filas=$(asterisk -rx "queue show" | grep -B 2 "$tech_type/$ramal" | grep "^[0-9]* has [0-9]* calls" | awk '{print $1}' | tr '\n' ',' | sed 's/,$//')
  
  echo "$filas"
}

# Função principal de monitoramento
monitor_cycle() {
  if [[ ! -f "$CONFIG_FILE" ]]; then
    log_msg "✗ Arquivo de configuração não encontrado: $CONFIG_FILE"
    return 1
  fi

  if [[ ! -x "$SCRIPT_DIR/fop2_pauses.sh" ]] || [[ ! -x "$SCRIPT_DIR/fop2_unpause.sh" ]]; then
    log_msg "✗ Scripts necessários não encontrados ou sem permissão de execução"
    return 1
  fi

  local pausas_atuais=$("$SCRIPT_DIR/fop2_pauses.sh" --no-header 2>/dev/null)

  if [[ -z "$pausas_atuais" ]]; then
    return 0
  fi

  local total_verificados=0
  local total_despausados=0
  local total_notificados=0

  while IFS=';' read -r ramal nome pausa duracao; do
    [[ -z "$ramal" ]] && continue
    
    total_verificados=$((total_verificados + 1))
    local duracao_minutos=$(duracao_para_minutos "$duracao")
    
    local acao_executar=""
    local limite_atingido=0
    local max_minutos=""
    local regra_encontrada=0
    local intervalo_notificacao=0
    
    while IFS='|' read -r pausa_config max_min acao intervalo; do
      [[ "$pausa_config" =~ ^#.*$ ]] || [[ -z "$pausa_config" ]] && continue
      
      pausa_config=$(echo "$pausa_config" | xargs)
      max_min=$(echo "$max_min" | xargs)
      acao=$(echo "$acao" | xargs)
      intervalo=$(echo "$intervalo" | xargs)
      
      [[ -z "$intervalo" ]] && intervalo=0
      
      if [[ "$pausa_config" == "$pausa" ]]; then
        regra_encontrada=1
        max_minutos="$max_min"
        intervalo_notificacao="$intervalo"
        
        if [[ $duracao_minutos -gt $max_minutos ]]; then
          acao_executar="$acao"
          limite_atingido=1
        fi
        break
      fi
      
    done < "$CONFIG_FILE"
    
    if [[ $regra_encontrada -eq 0 ]]; then
      continue
    fi
    
    if [[ $limite_atingido -eq 1 ]]; then
      
      case "$acao_executar" in
        despausar)
          # Detecta tecnologia
          local tech_type=$(asterisk -rx "queue show" | grep -oE "(PJSIP|SIP)/$ramal" | head -n1 | cut -d'/' -f1)
          
          # Captura filas antes de despausar
          local filas=$(get_filas_despausadas "$ramal" "$tech_type")
          
          if "$SCRIPT_DIR/fop2_unpause.sh" "$ramal" >> "$LOG_FILE" 2>&1; then
            log_msg "✓ DESPAUSADO | Ramal: $ramal ($nome) | Pausa: $pausa | Tempo: $duracao (${duracao_minutos}min) | Limite: ${max_minutos}min"
            total_despausados=$((total_despausados + 1))
            
            # Log CSV estruturado
            log_csv "$ramal" "$nome" "$pausa" "$duracao" "${max_minutos}min" "DESPAUSADO" "$filas"
            
            # Notifica supervisores
            send_toast_notification "$ramal" "$nome" "$pausa" "DESPAUSADO"
          else
            log_msg "✗ ERRO | Falha ao despausar ramal $ramal"
            log_csv "$ramal" "$nome" "$pausa" "$duracao" "${max_minutos}min" "ERRO_DESPAUSAR" "N/A"
          fi
          ;;
        notificar)
          if should_notify "$ramal" "$pausa" "$intervalo_notificacao"; then
            log_msg "⚠ NOTIFICAÇÃO | Ramal: $ramal ($nome) | Pausa: $pausa | Tempo: $duracao (${duracao_minutos}min) | Limite: ${max_minutos}min"
            total_notificados=$((total_notificados + 1))
            
            # Log CSV estruturado
            log_csv "$ramal" "$nome" "$pausa" "$duracao" "${max_minutos}min" "NOTIFICAÇÃO" "N/A"
            
            # Notifica supervisores
            send_toast_notification "$ramal" "$nome" "$pausa" "NOTIFICAÇÃO" "$duracao" "${max_minutos}min"
          fi
          ;;
      esac
    fi
    
  done <<< "$pausas_atuais"

  if [[ $total_despausados -gt 0 ]] || [[ $total_notificados -gt 0 ]]; then
    log_msg "📊 Ciclo: $total_verificados verificados | $total_despausados despausados | $total_notificados notificados"
  fi
}

# Função de limpeza periódica (executa a cada hora)
periodic_cleanup() {
  local last_cleanup_file="/var/run/fop2_last_cleanup"
  local now=$(date +%s)
  
  # Se arquivo não existe, cria
  if [[ ! -f "$last_cleanup_file" ]]; then
    echo "$now" > "$last_cleanup_file"
    return
  fi
  
  local last_cleanup=$(cat "$last_cleanup_file")
  local elapsed=$((now - last_cleanup))
  
  # Limpa a cada hora (3600 segundos)
  if [[ $elapsed -ge 3600 ]]; then
    log_msg "🧹 Executando limpeza de cache..."
    clean_old_cache
    echo "$now" > "$last_cleanup_file"
    
    # Mostra estatísticas do cache
    if [[ -f "$NOTIFICATION_CACHE" ]]; then
      local cache_lines=$(wc -l < "$NOTIFICATION_CACHE")
      log_msg "📊 Cache: $cache_lines entradas ativas"
    fi
  fi
}

# Início do daemon
log_msg "=========================================="
log_msg "🚀 FOP2 Pause Monitor iniciado"
log_msg "📁 Diretório: $SCRIPT_DIR"
log_msg "⏱️  Intervalo: ${CHECK_INTERVAL}s"
log_msg "📝 Log CSV: $CSV_LOG"
log_msg "💾 Cache: $NOTIFICATION_CACHE"
log_msg "=========================================="

# Cria cabeçalho CSV se não existir
if [[ ! -f "$CSV_LOG" ]]; then
  echo "timestamp;ramal;nome;pausa;tempo_atual;tempo_limite;acao;filas" > "$CSV_LOG"
fi

# Loop infinito
while true; do
  monitor_cycle
  periodic_cleanup
  sleep "$CHECK_INTERVAL"
done