SET NAMES utf8mb4;

-- api_chaves.chave_preview estava VARCHAR(8), mas o preview gerado em
-- web/ikarus37/api.php é '...' + 6 caracteres = 9 caracteres — nenhuma
-- chave nova cabia ali, e o cadastro falhava com "Data too long for
-- column 'chave_preview'". A chave semeada em init_db.sql (9 caracteres,
-- '...f058') só existia porque foi inserida direto via SQL, sem passar
-- pela validação de tamanho do MySQL em modo estrito.
ALTER TABLE api_chaves MODIFY chave_preview VARCHAR(20) NOT NULL;
