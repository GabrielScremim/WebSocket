#!/bin/bash

echo "🚀 Iniciando Sistema de Monitoramento de Servidores"
echo "=================================================="

# Verifica se o composer está instalado
if ! command -v composer &> /dev/null; then
    echo "❌ Composer não encontrado. Por favor, instale o Composer primeiro."
    echo "   Visite: https://getcomposer.org/"
    exit 1
fi

# Instala dependências
echo "📦 Instalando dependências..."
composer install --no-dev --optimize-autoloader

# Verifica se as dependências foram instaladas
if [ ! -d "vendor" ]; then
    echo "❌ Erro ao instalar dependências"
    exit 1
fi

echo "✅ Dependências instaladas com sucesso!"
echo ""
echo "🌐 Para usar o sistema:"
echo "   1. Execute: php servidor_websocket.php"
echo "   2. Abra o arquivo monitor.html no navegador"
echo "   3. O sistema começará a monitorar os servidores automaticamente"
echo ""
echo "📡 O WebSocket estará disponível em: ws://localhost:8080"
echo ""
echo "⚙️  Configuração:"
echo "   - Edite o array \$servers_to_monitor no arquivo servidor_websocket.php"
echo "   - Adicione os servidores que deseja monitorar"
echo ""
echo "🔧 Iniciando servidor WebSocket..."
php servidor_websocket.php