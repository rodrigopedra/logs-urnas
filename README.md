# Logs das Urnas - Eleições 2022

Comandos para baixar os logs de votação do TSE e analisar o tempo entre votos.

## Requisitos

- PHP 8.1 (com extensão para MySQL)
- composer
- ver requisitos adicionais para 7z em https://github.com/Gemorroj/Archive7z/blob/3b8d1a78c49fa2ca0ba00223d9f38c8198e5d10e/README.md
- MySQL 8.0

## Instalacão

~~~bash
composer require rodrigopedra/logs-urnas
cd logs-urnas
cp .env.example .env
composer install
~~~

- Altera o arquivo `.env` na raiz do projeto com suas credenciais para o servidor MySQL

~~~dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3360
DB_DATABASE=nome_do_banco
DB_USERNAME=usuario
DB_PASSWORD=senha
~~~

**ATENÇÃO:** O banco deve existir no servidor MySQL.

## Utilização

### Baixar zips com os logs

~~~bash
php logs-urnas logs:download {uf} {minutos}
~~~

Este comando baixa os arquivos ZIP do CDN do TSE.

- O parâmetro `{uf}` aceita uma sigla da UF em maiúsculas (por exemplo `SP`),
  ou `ZZ` para os votos no exterior (padrão adotado pelo TSE)
- O parâmetro `{minutos}` indica o máximo de minutos para cancelar o download de um arquivo.
  Caso não seja informado, é assumido o valor `60` (1 hora)

*Como os logs são cumulativos, os arquivos baixados são os que incluem o 2o. turno*

### Processar e importar os arquivos de log no banco de dados

~~~bash
php logs-urnas logs:processa {uf} {turno}
~~~

Este comando processa o arquivo ZIP da UF informada  e importa os logs de votação concluída 
para o banco de dados.

- O parâmetro `{uf}` aceita uma sigla da UF em maiúsculas (por exemplo `SP`),
  ou `ZZ` para os votos no exterior (padrão adotado pelo TSE)
- O parâmetro `{turno}` aceita os valores `1` (1o. turno) ou `2` (2o. turno)

Após a execução uma tabela com o padrão de nome `logs_uf_turno` será criada no banco de dados.

**ATENÇÃO** caso esta tabela já exista, ela será removida e recriada.

### Frequência de votos por segundos após o último voto

~~~bash
php logs-urnas logs:frequencias {uf} {turno}
~~~

Este comando gera um arquivo CSV com a frequência de votos por segundos após o último voto.

- O parâmetro `{uf}` aceita uma sigla da UF em maiúsculas (por exemplo `SP`),
  ou `ZZ` para os votos no exterior (padrão adotado pelo TSE)
- O parâmetro `{turno}` aceita os valores `1` (1o. turno) ou `2` (2o. turno)

**ATENÇÃO** a linha com a coluna `segundos_depois` igual a `-1`, se referem aos primeiros 
votos de cada urna, por não terem votos antes deles mesmo. 

### Sequências de votos menor que segundos

~~~bash
php logs-urnas logs:sequencias {uf} {turno} {segundos} {quantidade}
~~~

Busca sequências de votos com diferença de tempo menor ou igual aos segundos informados

- O parâmetro `{uf}` aceita uma sigla da UF em maiúsculas (por exemplo `SP`),
  ou `ZZ` para os votos no exterior (padrão adotado pelo TSE)
- O parâmetro `{turno}` aceita os valores `1` (1o. turno) ou `2` (2o. turno)
- O parâmetro `{segundos}` indica o máximo de segundos a ser considerado entre um voto e outro 
  em uma sequência
- O parâmetro `{quantidade}` indica o número mínimo de votos consecutivos 
  para que seja considerada uma sequência. Caso não seja informado, é assumido o valor `5`

**ATENÇÃO** a linha com a coluna `segundos_depois` igual a `-1`, se referem aos primeiros 
votos de cada sequência. 
