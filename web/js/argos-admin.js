// Painel de Comando e Ikarus37 — tabelas enxutas com painel lateral.
//
// Uma tabela marcada com data-enxuta mostra só as colunas essenciais; o resto
// (e a edição) abre num painel lateral ao clicar em "Ver / editar" na linha.
//
//   <table data-enxuta="Nome|Cargo|Status">   colunas que ficam, pelo título
//   <table data-enxuta="2">                   ou as N primeiras
//
// Não muda o PHP das páginas: o painel recebe os próprios campos da linha
// (os inputs continuam ligados ao <form> deles pelo atributo form=), então
// salvar, excluir e os links funcionam igual. Sem JavaScript a tabela aparece
// inteira, como antes.

(() => {
    const CONTROLES = 'input:not([type=hidden]), select, textarea, button, form';

    let painel = null;
    let devolucoes = [];
    let focoAnterior = null;

    function criarPainel() {
        const fundo = document.createElement('div');
        fundo.className = 'painel-fundo';
        fundo.hidden = true;
        fundo.innerHTML = `
            <aside class="painel-lateral" role="dialog" aria-modal="true" aria-labelledby="painelTitulo" tabindex="-1">
                <header class="painel-cabecalho">
                    <h3 id="painelTitulo"></h3>
                    <button type="button" class="painel-fechar ghost" aria-label="Fechar painel">Fechar</button>
                </header>
                <div class="painel-corpo"></div>
            </aside>`;
        document.body.appendChild(fundo);

        fundo.addEventListener('click', (evento) => {
            if (evento.target === fundo) fechar();
        });
        fundo.querySelector('.painel-fechar').addEventListener('click', fechar);
        document.addEventListener('keydown', (evento) => {
            if (evento.key === 'Escape' && !fundo.hidden) fechar();
        });
        return fundo;
    }

    // Texto que resume uma célula que tem campos: o valor digitado, a opção
    // escolhida, o texto solto (ex: o badge "ativo").
    function resumoDaCelula(celula) {
        const partes = [];
        const visitar = (no) => {
            if (no.nodeType === Node.TEXT_NODE) {
                partes.push(no.textContent);
            } else if (no.nodeType === Node.ELEMENT_NODE) {
                if (no.matches('select')) {
                    partes.push(no.selectedOptions[0] ? no.selectedOptions[0].textContent : '');
                } else if (no.matches('input[type=checkbox], input[type=radio], input[type=hidden], button, script')) {
                    // não entra no resumo
                } else if (no.matches('input, textarea')) {
                    if (no.style.display !== 'none') partes.push(no.value);
                } else {
                    no.childNodes.forEach(visitar);
                }
            }
        };
        celula.querySelector('.celula-original').childNodes.forEach(visitar);
        return partes.join(' ').replace(/\s+/g, ' ').trim() || '—';
    }

    function abrir(linha, titulos) {
        painel = painel || criarPainel();
        const corpo = painel.querySelector('.painel-corpo');
        corpo.innerHTML = '';
        devolucoes = [];
        focoAnterior = document.activeElement;

        [...linha.cells].forEach((celula, i) => {
            const titulo = titulos[i];
            if (!titulo || celula.classList.contains('celula-abrir')) return;

            const campo = document.createElement('div');
            campo.className = 'painel-campo';
            const rotulo = document.createElement('div');
            rotulo.className = 'painel-rotulo';
            rotulo.textContent = titulo;
            const valor = document.createElement('div');
            valor.className = 'painel-valor';

            const original = celula.querySelector('.celula-original');
            if (original) {
                // Célula com campos (ou escondida): o conteúdo de verdade vem pro painel.
                devolucoes.push([original, celula]);
                valor.appendChild(original);
                original.hidden = false;
            } else {
                valor.innerHTML = celula.innerHTML.trim() || '—';
            }

            campo.append(rotulo, valor);
            corpo.appendChild(campo);
        });

        painel.querySelector('#painelTitulo').textContent = linha.dataset.tituloPainel || 'Detalhes';
        painel.hidden = false;
        document.body.classList.add('painel-aberto');
        painel.querySelector('.painel-lateral').focus();
    }

    function fechar() {
        if (!painel || painel.hidden) return;
        devolucoes.forEach(([original, celula]) => {
            original.hidden = true;
            celula.appendChild(original);
            const resumo = celula.querySelector('.celula-resumo');
            if (resumo) resumo.textContent = resumoDaCelula(celula);
        });
        devolucoes = [];
        painel.hidden = true;
        document.body.classList.remove('painel-aberto');
        if (focoAnterior) focoAnterior.focus();
    }

    function prepararTabela(tabela) {
        const linhas = [...tabela.rows];
        const cabecalho = linhas.find((l) => l.querySelector('th'));
        if (!cabecalho) return;

        const titulos = [...cabecalho.cells].map((c) => c.textContent.trim());
        const regra = tabela.dataset.enxuta.trim();
        const visiveis = /^\d+$/.test(regra)
            ? titulos.map((t, i) => i < Number(regra))
            : titulos.map((t) => t === '' || regra.split('|').includes(t));

        if (visiveis.every(Boolean)) return;

        let temEdicao = false;
        const linhasDeDados = linhas.filter((l) => l !== cabecalho && l.cells.length === titulos.length);

        linhasDeDados.forEach((linha) => {
            [...linha.cells].forEach((celula, i) => {
                const temControles = celula.querySelector(CONTROLES) !== null;
                // Coluna sem título (ex: caixa de seleção) fica como está.
                if (titulos[i] === '' || (visiveis[i] && !temControles)) return;

                if (temControles || celula.querySelector('a')) temEdicao = true;

                const original = document.createElement('div');
                original.className = 'celula-original';
                original.hidden = true;
                while (celula.firstChild) original.appendChild(celula.firstChild);
                celula.appendChild(original);

                if (visiveis[i]) {
                    const resumo = document.createElement('span');
                    resumo.className = 'celula-resumo';
                    celula.prepend(resumo);
                    resumo.textContent = resumoDaCelula(celula);
                } else {
                    celula.classList.add('coluna-oculta');
                }
            });
        });

        [...cabecalho.cells].forEach((c, i) => {
            if (!visiveis[i]) c.classList.add('coluna-oculta');
        });

        const rotuloBotao = temEdicao ? 'Ver / editar' : 'Ver';
        cabecalho.appendChild(document.createElement('th')).className = 'celula-abrir';

        linhasDeDados.forEach((linha) => {
            const primeira = [...linha.cells].find((c, i) => titulos[i] !== '' && visiveis[i]);
            linha.dataset.tituloPainel = primeira ? (primeira.querySelector('.celula-resumo') || primeira).textContent.trim() : '';

            const celula = linha.insertCell();
            celula.className = 'celula-abrir';
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'ghost botao-abrir';
            botao.textContent = rotuloBotao;
            botao.addEventListener('click', () => abrir(linha, titulos));
            celula.appendChild(botao);
        });

        // Linhas avulsas ("nenhum registro") continuam ocupando a tabela toda.
        linhas.filter((l) => l !== cabecalho && !linhasDeDados.includes(l)).forEach((l) => {
            if (l.cells.length === 1) l.cells[0].colSpan = titulos.length + 1;
        });

        tabela.classList.add('tabela-enxuta');
    }

    document.querySelectorAll('table[data-enxuta]').forEach(prepararTabela);
})();
