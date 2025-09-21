#!/bin/bash

# Script de instalação automática do Sistema de Monitoramento de Servidores
# Para Linux (Ubuntu/Debian/CentOS/RHEL)

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Funções auxiliares
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCESSO]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[AVISO]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERRO]${NC} $1"
}

# Detectar distribuição Linux
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
    else
        print_error "Não foi possível detectar a distribuição Linux"
        exit 1
    fi
    
    print_status "Distribuição detectada: $OS $VER"
}

# Instalar PHP e dependências
install_php() {
    print_status "Instalando PHP e extensões necessárias..."
    
    case "$OS" in
        "Ubuntu"*|"Debian"*)
            sudo apt update
            sudo apt install -y php php-cli php-curl php-json php-mbstring php-xml php-zip php-sockets
            ;;
        "CentOS"*|"Red Hat"*|"Rocky Linux"*)
            sudo dnf install -y epel-release
            sudo dnf install -y php php-cli php-curl php-json php-mbstring php-xml php-zip php-process
            ;;
        "Arch Linux"*)
            sudo pacman -S --noconfirm php php-curl
            ;;
        *)
            print_error "Distribuição não suportada: $OS"
            exit 1
            ;;
    esac
    
    # Verificar instalação do PHP
    if command -v php &> /dev/null; then
        PHP_VERSION=$(php --version | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
        print_success "PHP $PHP_VERSION instalado com sucesso"
    else
        print_error "Falha na instalação do PHP"
        exit 1
    fi
}

# Instalar Composer
install_composer() {
    print_status "Instalando Composer..."
    
    if command -v composer &> /dev/null; then
        print_warning "Composer já está instalado"
        return 0
    fi
    
    # Baixar e instalar Composer
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
    
    # Verificar instalação
    if command -v composer &> /dev/null; then
        COMPOSER_VERSION=$(composer --version | cut -d' ' -f3)
        print_success "Composer $COMPOSER_VERSION instalado com sucesso"
    else
        print_error "Falha na instalação do Composer"
        exit 1
    fi
}

# Criar estrutura do projeto
setup_project() {
    print_status "Configurando estrutura do projeto..."
    
    # Criar diretório do projeto
    PROJECT_DIR="$HOME/server-monitor"
    mkdir -p "$PROJECT_DIR"
    cd "$PROJECT_DIR"
    
    # Criar diretórios necessários
    mkdir -p logs backups
    
    print_success "Estrutura do projeto criada em: $PROJECT_DIR"
}

# Configurar firewall
configure_firewall() {
    print_status "Configurando firewall..."
    
    # Detectar sistema de firewall
    if command -v ufw &> /dev/null; then
        # Ubuntu/Debian - UFW
        sudo ufw allow 8080/tcp
        print_success "Porta 8080 liberada no UFW"
    elif command -v firewall-cmd &> /dev/null; then
        # CentOS/RHEL - firewalld
        sudo firewall-cmd --permanent --add-port=8080/tcp
        sudo firewall-cmd --reload
        print_success "Porta 8080 liberada no firewalld"
    else
        print_warning "Sistema de firewall não detectado. Certifique-se de liberar a porta 8080"
    fi
}

# Criar arquivo de serviço systemd
create_service() {
    print_status "Criando serviço systemd..."
    
    SERVICE_FILE="/etc/systemd/system/server-monitor.service"
    
    sudo tee "$SERVICE_FILE" > /dev/null << EOF
[Unit]
Description=Server Monitor WebSocket Service
After=network.target

[Service]
Type=simple
User=$USER
Group=$USER
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php $PROJECT_DIR/servidor_websocket.php
Restart=always
RestartSec=10
StandardOutput=file:$PROJECT_DIR/logs/service.log
StandardError=file:$PROJECT_DIR/logs/service-error.log

[Install]
WantedBy=multi-user.target
EOF

    # Configurar systemd
    sudo systemctl daemon-reload
    sudo systemctl enable server-monitor
    
    print_success "Serviço systemd criado e habilitado"
}

# Otimizar configuração do PHP
optimize_php() {
    print_status "Otimizando configuração do PHP..."
    
    # Encontrar arquivo php.ini para CLI
    PHP_INI=$(php --ini | grep "Loaded Configuration File" | cut -d':' -f2 | xargs)
    
    if [ -n "$PHP_INI" ] && [ -f "$PHP_INI" ]; then
        # Backup do arquivo original
        sudo cp "$PHP_INI" "$PHP_INI.backup"
        
        # Aplicar otimizações
        sudo sed -i 's/max_execution_time = .*/max_execution_time = 0/' "$PHP_INI"
        sudo sed -i 's/memory_limit = .*/memory_limit = 256M/' "$PHP_INI"
        
        print_success "Configuração do PHP otimizada"
    else
        print_warning "Arquivo php.ini não encontrado para otimização"
    fi
}

# Criar scripts auxiliares
create_scripts() {
    print_status "Criando scripts auxiliares..."
    
    # Script de inicialização manual
    cat > "$PROJECT_DIR/start.sh" << 'EOF'
#!/bin/bash
echo "🚀 Iniciando Sistema de Monitoramento de Servidores"
echo "=================================================="

cd "$(dirname "$0")"

# Verificar se as dependências estão instaladas
if [ ! -d "vendor" ]; then
    echo "📦 Instalando dependências..."
    composer install --no-dev --optimize-autoloader
fi

echo "▶️  Iniciando servidor WebSocket na porta 8080..."
echo "🌐 Abra o arquivo monitor.html no navegador"
echo "🛑 Para parar o servidor, pressione Ctrl+C"
echo ""

php servidor_websocket.php
EOF

    # Script de parada
    cat > "$PROJECT_DIR/stop.sh" << 'EOF'
#!/bin/bash
echo "🛑 Parando Sistema de Monitoramento..."

# Parar serviço systemd se estiver rodando
if systemctl is-active --quiet server-monitor; then
    sudo systemctl stop server-monitor
    echo "✅ Serviço systemd parado"
fi

# Parar processos manuais
pkill -f "servidor_websocket.php" && echo "✅ Processos manuais parados" || echo "ℹ️  Nenhum processo manual encontrado"
EOF

    # Script de status
    cat > "$PROJECT_DIR/status.sh" << 'EOF'
#!/bin/bash
echo "📊 Status do Sistema de Monitoramento"
echo "===================================="

# Status do serviço
echo "🔧 Serviço systemd:"
systemctl is-active server-monitor && echo "  ✅ Ativo" || echo "  ❌ Inativo"

# Status da porta
echo "🌐 Porta 8080:"
if netstat -tuln | grep -q ":8080 "; then
    echo "  ✅ Em uso"
else
    echo "  ❌ Não está sendo usada"
fi

# Processos relacionados
echo "🔍 Processos ativos:"
pgrep -f "servidor_websocket.php" > /dev/null && echo "  ✅ Processo encontrado" || echo "  ❌ Nenhum processo ativo"

# Logs recentes
echo "📝 Últimas 5 linhas do log:"
if [ -f "logs/service.log" ]; then
    tail -5 logs/service.log | sed 's/^/  /'
else
    echo "  ℹ️  Arquivo de log não encontrado"
fi
EOF

    # Tornar scripts executáveis
    chmod +x "$PROJECT_DIR"/*.sh
    
    print_success "Scripts auxiliares criados"
}

# Verificar requisitos
check_requirements() {
    print_status "Verificando requisitos do sistema..."
    
    # Verificar se é root para certas operações
    if [[ $EUID -eq 0 ]]; then
        print_error "Este script não deve ser executado como root"
        print_warning "Execute como usuário normal. O sudo será solicitado quando necessário"
        exit 1
    fi
    
    # Verificar conectividade
    if ! curl -s --connect-timeout 5 https://getcomposer.org > /dev/null; then
        print_error "Sem conectividade com a internet"
        exit 1
    fi
    
    print_success "Requisitos verificados"
}

# Instalar dependências do projeto
install_dependencies() {
    print_status "Instalando dependências do projeto..."
    
    cd "$PROJECT_DIR"
    composer install --no-dev --optimize-autoloader
    
    print_success "Dependências instaladas"
}

# Função principal
main() {
    echo "🐧 Instalador do Sistema de Monitoramento de Servidores para Linux"
    echo "=================================================================="
    echo ""
    
    # Verificar requisitos
    check_requirements
    
    # Detectar sistema operacional
    detect_os
    
    # Instalações
    install_php
    install_composer
    
    # Configuração do projeto
    setup_project
    
    # Os arquivos do projeto devem ser copiados aqui
    print_warning "IMPORTANTE: Copie os arquivos do projeto para $PROJECT_DIR"
    print_warning "Arquivos necessários:"
    print_warning "  - servidor_websocket.php"
    print_warning "  - monitor.html"
    print_warning "  - composer.json"
    
    # Aguardar confirmação
    read -p "Pressione Enter após copiar os arquivos do projeto..."
    
    # Verificar se os arquivos existem
    if [ ! -f "$PROJECT_DIR/servidor_websocket.php" ]; then
        print_error "Arquivo servidor_websocket.php não encontrado!"
        print_error "Copie os arquivos do projeto para $PROJECT_DIR antes de continuar"
        exit 1
    fi
    
    # Continuar instalação
    install_dependencies
    configure_firewall
    optimize_php
    create_service
    create_scripts
    
    echo ""
    print_success "🎉 Instalação concluída com sucesso!"
    echo ""
    echo "📋 Próximos passos:"
    echo "   1. Edite $PROJECT_DIR/servidor_websocket.php para configurar os servidores"
    echo "   2. Iniciar serviço: sudo systemctl start server-monitor"
    echo "   3. Abrir monitor.html no navegador"
    echo ""
    echo "🔧 Comandos úteis:"
    echo "   - Iniciar manual: $PROJECT_DIR/start.sh"
    echo "   - Parar serviço: $PROJECT_DIR/stop.sh"
    echo "   - Ver status: $PROJECT_DIR/status.sh"
    echo "   - Ver logs: journalctl -u server-monitor -f"
    echo ""
    echo "🌐 WebSocket estará disponível em: ws://localhost:8080"
}

# Executar função principal
main "$@"