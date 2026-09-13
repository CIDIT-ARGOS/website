document.addEventListener('DOMContentLoaded', function () {

  // ---------- Menu mobile ----------
  var navToggle = document.getElementById('navToggle');
  var navLinks = document.getElementById('navLinks');

  if (navToggle && navLinks) {
    navToggle.addEventListener('click', function () {
      var aberto = navLinks.classList.toggle('open');
      navToggle.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });

    navLinks.querySelectorAll('.nav-link').forEach(function (link) {
      link.addEventListener('click', function () {
        navLinks.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // ---------- Link ativo no menu conforme a seção visível ----------
  var secoes = document.querySelectorAll('main section[id]');
  var linksNav = document.querySelectorAll('.nav-link');

  if (secoes.length && linksNav.length && 'IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        linksNav.forEach(function (link) {
          link.classList.remove('active');
        });

        var linkAtual = document.querySelector('.nav-link[href="#' + entry.target.id + '"]');
        if (linkAtual) {
          linkAtual.classList.add('active');
        }
      });
    }, { rootMargin: '-40% 0px -55% 0px' });

    secoes.forEach(function (secao) {
      observer.observe(secao);
    });
  }

  // ---------- Botão "voltar ao topo" ----------
  var backToTop = document.getElementById('backToTop');
  if (backToTop) {
    backToTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

});
