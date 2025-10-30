#!/bin/bash
# fop2_monitor_pauses.sh
# Monitora pausas em tempo real e executa ações baseadas em configuração

SCRIPT_DIR="/etc/guarida/scripts"
CONFIG_FILE="/etc/guarida/config/pause_monitor.conf"
LOG_FILE="/var/log/guarida/pause_monitor.log"

# Intervalo de verificação em segundos (ajuste conforme necessário)
CHECK_INTERVAL=10

# Função de log
log_msg() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Função para converter duração "HH:MM" ou "MM:SS" para minutos
duracao_para_minutos() {
  local duracao="$1"
  local parte1 parte2
  
  IFS=':' read -r parte1 parte2 <<< "$duracao"
  
  # Se parte1 > 60, assume que é minutos:segundos, senão horas:minutos
  if [[ $parte1 -gt 60 ]]; then
    echo "$parte1"  # já está em minutos
  else
    echo $((parte1 * 60 + parte2))  # converte horas:minutos para minutos
  fi
}

# Função principal de monitoramento
monitor_cycle() {
  # Verifica se arquivo de config existe
  if [[ ! -f "$CONFIG_FILE" ]]; then
    log_msg "✗ Arquivo de configuração não encontrado: $CONFIG_FILE"
    return 1
  fi

  # Verifica se scripts necessários existem
  if [[ ! -x "$SCRIPT_DIR/fop2_pauses.sh" ]] || [[ ! -x "$SCRIPT_DIR/fop2_unpause.sh" ]]; then
    log_msg "✗ Scripts necessários não encontrados ou sem permissão de execução"
    return 1
  fi

  # Coleta todas as pausas atuais
  local pausas_atuais=$("$SCRIPT_DIR/fop2_pauses.sh" --no-header 2>/dev/null)

  if [[ -z "$pausas_atuais" ]]; then
    return 0  # Nenhum ramal pausado, continua monitorando
  fi

  # Contadores
  local total_verificados=0
  local total_despausados=0
  local total_notificados=0

  # Processa cada pausa encontrada
  while IFS=';' read -r ramal nome pausa duracao; do
    [[ -z "$ramal" ]] && continue
    
    total_verificados=$((total_verificados + 1))
    local duracao_minutos=$(duracao_para_minutos "$duracao")
    
    # Variáveis para controle
    local acao_executar=""
    local limite_atingido=0
    local max_minutos=""
    local regra_encontrada=0
    
    # Lê arquivo de configuração e procura regra para esta pausa
    while IFS='|' read -r pausa_config max_min acao; do
      # Ignora comentários e linhas vazias
      [[ "$pausa_config" =~ ^#.*$ ]] || [[ -z "$pausa_config" ]] && continue
      
      # Remove espaços em branco
      pausa_config=$(echo "$pausa_config" | xargs)
      max_min=$(echo "$max_min" | xargs)
      acao=$(echo "$acao" | xargs)
      
      # Verifica se a pausa corresponde
      if [[ "$pausa_config" == "$pausa" ]]; then
        regra_encontrada=1
        max_minutos="$max_min"
        
        if [[ $duracao_minutos -gt $max_minutos ]]; then
          acao_executar="$acao"
          limite_atingido=1
        fi
        break
      fi
      
    done < "$CONFIG_FILE"
    
    # Se não encontrou regra, continua
    if [[ $regra_encontrada -eq 0 ]]; then
      continue
    fi
    
    # Executa ação se necessário
    if [[ $limite_atingido -eq 1 ]]; then
      log_msg "⚠ LIMITE EXCEDIDO! Ramal: $ramal ($nome) | Pausa: '$pausa' | Tempo: ${duracao} (${duracao_minutos}min) | Max: ${max_minutos}min"
      
      case "$acao_executar" in
        despausar)
          log_msg "→ Despausando ramal $ramal..."
          if "$SCRIPT_DIR/fop2_unpause.sh" "$ramal" >> "$LOG_FILE" 2>&1; then
            log_msg "✓ Ramal $ramal despausado com sucesso"
            total_despausados=$((total_despausados + 1))
          else
            log_msg "✗ Erro ao despausar ramal $ramal"
          fi
          ;;
        notificar)
          log_msg "→ NOTIFICAÇÃO: Ramal $ramal ($nome) excedeu tempo de pausa '$pausa' (${duracao_minutos}min)"
          # TODO: Implementar envio de notificação (Telegram, email, etc)
          total_notificados=$((total_notificados + 1))
          ;;
      esac
    fi
    
  done <<< "$pausas_atuais"

  # Log resumo se houve ações
  if [[ $total_despausados -gt 0 ]] || [[ $total_notificados -gt 0 ]]; then
    log_msg "Ciclo: $total_verificados verificados | $total_despausados despausados | $total_notificados notificados"
  fi
}

# Início do daemon
log_msg "=== Monitor de pausas iniciado (Intervalo: ${CHECK_INTERVAL}s) ==="

# Loop infinito
while true; do
  monitor_cycle
  sleep "$CHECK_INTERVAL"
done