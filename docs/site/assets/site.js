"use strict";

(() => {
    const menuButton = document.getElementById("menu-button");
    const navigation = document.getElementById("site-nav");

    if (menuButton && navigation) {
        const closeMenu = (restoreFocus = false) => {
            navigation.classList.remove("is-open");
            menuButton.setAttribute("aria-expanded", "false");
            if (restoreFocus) menuButton.focus();
        };
        menuButton.addEventListener("click", () => {
            const open = menuButton.getAttribute("aria-expanded") !== "true";
            navigation.classList.toggle("is-open", open);
            menuButton.setAttribute("aria-expanded", String(open));
        });
        navigation.addEventListener("click", (event) => {
            if (event.target instanceof Element && event.target.closest("a")) closeMenu();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && navigation.classList.contains("is-open")) closeMenu(true);
        });
        document.addEventListener("click", (event) => {
            if (!(event.target instanceof Node)) return;
            if (!navigation.contains(event.target) && !menuButton.contains(event.target)) closeMenu();
        });
        window.matchMedia("(min-width: 901px)").addEventListener("change", (event) => {
            if (event.matches) closeMenu();
        });
    }

    if (navigator.clipboard && window.isSecureContext) {
        document.querySelectorAll("pre").forEach((block) => {
            const code = block.querySelector("code");
            if (!code || !code.textContent.trim()) return;
            const button = document.createElement("button");
            button.type = "button";
            button.className = "copy-code";
            button.textContent = "Copier";
            button.setAttribute("aria-label", "Copier ce bloc de code");
            const status = document.createElement("span");
            status.className = "copy-status visually-hidden";
            status.setAttribute("role", "status");
            status.setAttribute("aria-live", "polite");
            block.classList.add("has-copy");
            block.append(button);
            block.after(status);
            let resetTimer;
            button.addEventListener("click", async () => {
                clearTimeout(resetTimer);
                try {
                    await navigator.clipboard.writeText(code.textContent);
                    button.textContent = "Copié";
                    status.classList.add("visually-hidden");
                    status.textContent = "Code copié dans le presse-papiers.";
                } catch {
                    button.textContent = "Copier";
                    status.classList.remove("visually-hidden");
                    status.textContent = "La copie n’est pas disponible. Sélectionnez le code pour le copier.";
                }
                resetTimer = setTimeout(() => { button.textContent = "Copier"; }, 2200);
            });
        });
    }

    const input = document.getElementById("component-search");
    const kind = document.getElementById("component-kind");
    const results = document.getElementById("search-results");
    const status = document.getElementById("search-status");
    const staticList = document.getElementById("component-list");
    const main = document.getElementById("main");
    if (!input || !results || !status || !staticList || !main) return;

    const normalize = (value) => value.normalize("NFKD").replace(/\p{M}/gu, "").toLowerCase();
    const root = new URL(main.dataset.root || "./", document.baseURI);
    const indexUrl = new URL(input.dataset.searchIndex || "assets/search-index.json", document.baseURI);
    let indexPromise;
    let updateNumber = 0;
    let debounceTimer;

    const safeUrl = (value) => {
        if (typeof value !== "string" || value.length > 512 || !value || /[\\\u0000-\u001f]/.test(value)) return null;
        try {
            const url = new URL(value, root);
            if (url.origin !== root.origin || !url.pathname.startsWith(root.pathname)) return null;
            if (!["http:", "https:", "file:"].includes(url.protocol)) return null;
            return url.href;
        } catch {
            return null;
        }
    };

    const loadIndex = () => {
        if (!indexPromise) {
            indexPromise = (async () => {
                if (indexUrl.origin !== root.origin || !indexUrl.pathname.startsWith(root.pathname)) throw new Error("Invalid index URL");
                const response = await fetch(indexUrl, { credentials: "same-origin" });
                if (!response.ok) throw new Error("Search index unavailable");
                const content = await response.text();
                if (content.length > 12_000_000) throw new Error("Search index too large");
                const records = JSON.parse(content);
                if (!Array.isArray(records) || records.length > 20_000) throw new Error("Invalid search index");
                return records.flatMap((record) => {
                    if (!record || typeof record !== "object") return [];
                    const url = safeUrl(record.url);
                    if (!url || typeof record.title !== "string" || record.title.length > 512) return [];
                    const description = typeof record.description === "string" ? record.description.slice(0, 1200) : "";
                    const namespace = typeof record.namespace === "string" ? record.namespace.slice(0, 512) : "";
                    const recordKind = typeof record.kind === "string" ? record.kind.slice(0, 80) : "";
                    const group = typeof record.group === "string" ? record.group.slice(0, 256) : "";
                    const shortName = typeof record.shortName === "string" ? record.shortName.slice(0, 256) : record.title;
                    const packageName = typeof record.package === "string" ? record.package.slice(0, 256) : "";
                    const optional = record.optional === true;
                    return [{ title: record.title, description, namespace, kind: recordKind, group, shortName, packageName, optional, url,
                        text: normalize(`${record.title} ${shortName} ${namespace} ${description} ${group} ${packageName}`) }];
                });
            })();
        }
        return indexPromise;
    };

    const updateAddress = () => {
        const url = new URL(window.location.href);
        const query = input.value.trim().slice(0, 200);
        const selectedKind = kind ? kind.value : "";
        if (query) url.searchParams.set("q", query);
        else url.searchParams.delete("q");
        if (selectedKind) url.searchParams.set("kind", selectedKind);
        else url.searchParams.delete("kind");
        window.history.replaceState(null, "", url);
    };

    const makeCard = (record) => {
        const card = document.createElement("a");
        card.className = "component-card";
        card.href = record.url;
        const badge = document.createElement("span");
        badge.className = "badge";
        badge.textContent = record.kind;
        const title = document.createElement("h3");
        title.textContent = record.shortName;
        const namespace = document.createElement("span");
        namespace.className = "namespace";
        namespace.textContent = record.namespace || record.title;
        card.append(badge, title, namespace);
        if (record.optional) {
            const packageBadge = document.createElement("span");
            packageBadge.className = "badge";
            packageBadge.textContent = `Optionnel · ${record.packageName}`;
            card.append(packageBadge);
        }
        if (record.description) {
            const description = document.createElement("p");
            description.textContent = record.description.length > 180 ? `${record.description.slice(0, 177)}…` : record.description;
            card.append(description);
        }
        return card;
    };

    const updateSearch = async () => {
        const currentUpdate = ++updateNumber;
        const query = normalize(input.value.trim().slice(0, 200));
        const selectedKind = kind ? kind.value : "";
        if (!query && !selectedKind) {
            results.replaceChildren();
            results.hidden = true;
            staticList.hidden = false;
            status.textContent = "Explorez les composants ou recherchez un nom, un espace de noms ou une notion.";
            return;
        }
        status.textContent = "Chargement de la référence…";
        try {
            const records = await loadIndex();
            if (currentUpdate !== updateNumber) return;
            const terms = query.split(/\s+/).filter(Boolean);
            const matches = records.filter((record) => (!selectedKind || record.kind === selectedKind)
                && terms.every((term) => record.text.includes(term)));
            matches.sort((left, right) => {
                const leftName = normalize(left.shortName);
                const rightName = normalize(right.shortName);
                const score = (name) => name === query ? 0 : name.startsWith(query) ? 1 : 2;
                return score(leftName) - score(rightName) || left.title.localeCompare(right.title, "fr");
            });
            const fragment = document.createDocumentFragment();
            matches.slice(0, 40).forEach((record) => fragment.append(makeCard(record)));
            if (!matches.length) {
                const empty = document.createElement("p");
                empty.className = "empty-state";
                empty.textContent = "Aucun composant ne correspond à cette recherche. Essayez un nom PDF comme Catalog, Font ou Stream.";
                fragment.append(empty);
            }
            results.replaceChildren(fragment);
            results.hidden = false;
            staticList.hidden = true;
            status.textContent = `${matches.length} composant${matches.length === 1 ? "" : "s"} trouvé${matches.length === 1 ? "" : "s"}.`
                + (matches.length > 40 ? " Les 40 premiers sont affichés ; précisez votre recherche pour réduire la liste." : "");
        } catch {
            if (currentUpdate !== updateNumber) return;
            results.hidden = true;
            staticList.hidden = false;
            status.textContent = "La recherche n’a pas pu être chargée. Les liens ci-dessous restent disponibles.";
        }
    };

    const readAddress = () => {
        const parameters = new URL(window.location.href).searchParams;
        input.value = (parameters.get("q") || "").slice(0, 200);
        if (kind) {
            const requested = parameters.get("kind") || "";
            kind.value = Array.from(kind.options).some((option) => option.value === requested) ? requested : "";
        }
        void updateSearch();
    };

    input.addEventListener("input", () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => { updateAddress(); void updateSearch(); }, 160);
    });
    if (kind) kind.addEventListener("change", () => { clearTimeout(debounceTimer); updateAddress(); void updateSearch(); });
    window.addEventListener("popstate", readAddress);
    readAddress();
})();
