// ==========================================================================
// 1. FUNÇÃO ISOLADA E ULTRA SEGURA APENAS PARA O MENU MOBILE
// ==========================================================================
const iniciarMenuMobile = () => {
    const hamburger = document.querySelector(".hamburger");
    const navMenu = document.querySelector(".nav-menu");
    const navLinks = document.querySelectorAll(".nav-menu a");

    if (!hamburger || !navMenu) {
        console.error("Erro: Botão .hamburger ou menu .nav-menu não foram encontrados no HTML.");
        return;
    }

    const toggleMenu = (e) => {
        e.preventDefault();
        e.stopPropagation();
        hamburger.classList.toggle("active");
        navMenu.classList.toggle("active");
    };

    hamburger.removeEventListener("click", toggleMenu);
    hamburger.addEventListener("click", toggleMenu);

    navLinks.forEach(link => {
        link.addEventListener("click", () => {
            hamburger.classList.remove("active");
            navMenu.classList.remove("active");
        });
    });
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciarMenuMobile);
} else {
    iniciarMenuMobile();
}


// ==========================================================================
// 2. SCRIPTS DE INTERAÇÃO AVANÇADA, MAPA E ANIMAÇÕES DE SCROLL
// ==========================================================================
document.addEventListener("DOMContentLoaded", () => {
    try {
        // --- ANIMACAO DO BANNER HERO (ENTRADA SEQUENCIAL) ---
        const heroElements = [
            document.querySelector(".hero .tag"),
            document.querySelector(".hero h1"),
            document.querySelector(".hero p"),
            document.querySelector(".hero .hero-buttons")
        ];
        heroElements.forEach((el, i) => {
            if (el) {
                el.style.opacity = "0";
                el.style.transform = "translateY(20px)";
                el.style.transition = "opacity 0.6s ease, transform 0.6s ease";
                setTimeout(() => {
                    el.style.opacity = "1";
                    el.style.transform = "translateY(0)";
                }, 150 * (i + 1));
            }
        });

        // --- EFEITO DINÂMICO NO HEADER (SHRINK HEADER ON SCROLL) ---
        const header = document.querySelector(".header");
        if (header) {
            window.addEventListener("scroll", () => {
                if (window.scrollY > 50) {
                    header.style.height = "80px";
                    header.style.boxShadow = "0 10px 30px -10px rgba(0, 0, 0, 0.1)";
                    header.style.background = "rgba(255, 255, 255, 0.98)";
                } else {
                    header.style.height = "80px";
                    header.style.boxShadow = "none";
                    header.style.background = "rgba(255, 255, 255, 0.95)";
                }
            }, { passive: true });
        }

        // --- INTERAÇÃO COMPLETA DO MAPA DINÂMICO ---
        const markers = document.querySelectorAll(".marker");
        const infoCard = document.getElementById("map-info-card");
        
        // Elementos internos do Info Card para atualização
        const infoTitle = document.getElementById("info-title");
        const infoSubtitle = document.getElementById("info-subtitle");
        const val1 = document.getElementById("val-1");
        const val2 = document.getElementById("val-2");
        const val3 = document.getElementById("val-3");
        const infoCode = document.getElementById("info-code");

        if (markers.length > 0 && infoCard) {
            markers.forEach(marker => {
                marker.addEventListener("click", (e) => {
                    const currentMarker = e.currentTarget;

                    // Reseta escala de todos e destaca o clicado
                    markers.forEach(m => m.style.transform = "scale(1)");
                    currentMarker.style.transform = "scale(1.3)";

                    // Efeito suave de Fade Out no Card antes de trocar os dados
                    infoCard.style.opacity = "0";
                    infoCard.style.transform = "translateY(10px)";
                    infoCard.style.transition = "opacity 0.25s ease, transform 0.25s ease";

                    setTimeout(() => {
                        // Coleta os atributos customizados do HTML
                        const cidade = currentMarker.getAttribute("data-city") || "";
                        const regiao = currentMarker.getAttribute("data-regiao") || "";
                        const atracao = currentMarker.getAttribute("data-atracao") || "";
                        const curiosidade = currentMarker.getAttribute("data-curiosidade") || "";

                        // Injeta os dados dinamicamente respeitando o layout do seu HTML
                        if (infoTitle) infoTitle.textContent = cidade;
                        if (infoSubtitle) infoSubtitle.textContent = regiao;
                        if (val1) val1.textContent = regiao;
                        if (val2) val2.textContent = atracao;
                        if (val3) val3.textContent = curiosidade;
                        if (infoCode) infoCode.textContent = cidade.substring(0,2).toUpperCase();

                        // Fade In com os novos dados carregados
                        infoCard.style.opacity = "1";
                        infoCard.style.transform = "translateY(0)";
                    }, 250);
                });
            });
        }

        // --- SCROLL REVEAL AVANÇADO (INTERSECTION OBSERVER) ---
        // Alvos automáticos mapeados a partir das classes do seu HTML
        const elementosParaAnimar = document.querySelectorAll(
            ".card, .cultural-card, .knowledge-card, .feature, .model-card, .timeline-item"
        );

        if (elementosParaAnimar.length > 0) {
            // Estilização inicial via JS de forma limpa para não quebrar o layout caso o JS falhe
            elementosParaAnimar.forEach(el => {
                el.style.opacity = "0";
                el.style.transform = "translateY(30px)";
                el.style.transition = "opacity 0.6s cubic-bezier(0.25, 1, 0.5, 1), transform 0.6s cubic-bezier(0.25, 1, 0.5, 1)";
            });

            const scrollObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        // Adiciona um pequeno delay baseado na posição para criar efeito cascata (stagger)
                        setTimeout(() => {
                            entry.target.style.opacity = "1";
                            entry.target.style.transform = "translateY(0)";
                            
                            // Gatilho especial caso o elemento seja da sua Timeline
                            if (entry.target.classList.contains("timeline-item")) {
                                entry.target.classList.add("active");
                            }
                        }, index % 3 * 100); // escalona o efeito em elementos lado a lado

                        scrollObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: "0px 0px -40px 0px" });

            elementosParaAnimar.forEach(el => scrollObserver.observe(el));
        }

        // --- CONTADOR PROGRESSIVO PARA AS ESTATÍSTICAS ---
        // Mapeia os elementos strong das estatísticas do Bambalouni e da feira do conhecimento
        const contadores = document.querySelectorAll(".stat strong, .stat-box h3");
        
        if (contadores.length > 0) {
            const iniciarContador = (elemento) => {
                const textoLimpo = elemento.textContent.trim();
                const valorFinal = parseInt(textoLimpo.replace(/\D/g, ""), 10);
                
                if (isNaN(valorFinal)) return;

                let valorAtual = 0;
                const duracaoEfeito = 2000; // 2 segundos
                const incrementoPorFrame = valorFinal / (duracaoEfeito / 16.6); // baseado em 60fps

                const rodarContagem = () => {
                    valorAtual += incrementoPorFrame;
                    if (valorAtual >= valorFinal) {
                        elemento.textContent = textoLimpo; // Devolve a string final com sufixos (Ex: 100%, 20+, 4.9)
                    } else {
                        const sufixo = textoLimpo.replace(/[0-9]/g, "");
                        // Trata números decimais caso encontre (ex: 4.9)
                        if (textoLimpo.includes(".") || textoLimpo.includes(",")) {
                            elemento.textContent = (valorAtual).toFixed(1).replace(".", ",") + sufixo;
                        } else {
                            elemento.textContent = Math.floor(valorAtual) + sufixo;
                        }
                        requestAnimationFrame(rodarContagem);
                    }
                };
                requestAnimationFrame(rodarContagem);
            };

            const statsObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        iniciarContador(entry.target);
                        statsObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.6 });

            contadores.forEach(num => statsObserver.observe(num));
        }

    } catch (error) {
        console.warn("Aviso no bloco de interações: ", error);
    }
});

// ==========================================================================
// 3. VÍDEO DO HERO: respeita economia de dados / movimento reduzido e pausa fora da tela
// ==========================================================================
(function () {
    const video = document.querySelector("[data-hero-video]");
    if (!video) return;

    const conn = navigator.connection || {};
    const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const slowNetwork = conn.saveData === true || /(^|-)2g$/.test(conn.effectiveType || "");

    if (reduceMotion || slowNetwork) {
        // Mantém apenas o poster (WebP leve) e cancela o download do vídeo.
        video.removeAttribute("autoplay");
        video.pause();
        const source = video.querySelector("source");
        if (source) {
            source.removeAttribute("src");
            video.load();
        }
        return;
    }

    if ("IntersectionObserver" in window) {
        new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const playing = video.play();
                    if (playing && playing.catch) playing.catch(() => {});
                } else {
                    video.pause();
                }
            });
        }, { threshold: 0.1 }).observe(video);
    }
})();

// ==========================================================================
// 4. ANIMAÇÃO DAS SEÇÕES (configurada no painel): revela ao entrar na tela
// ==========================================================================
(function () {
    const targets = document.querySelectorAll(".sx-anim");
    if (!targets.length) return;
    if (!("IntersectionObserver" in window)) {
        targets.forEach((el) => el.classList.add("sx-in"));
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add("sx-in");
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });
    targets.forEach((el) => observer.observe(el));
})();
