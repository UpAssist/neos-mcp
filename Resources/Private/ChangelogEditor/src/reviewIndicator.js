/**
 * Review Status Indicator
 *
 * Finds the Redux store via React fiber BFS, reads reviewStatus from
 * node data, and matches tree items by their props.id (contextPath).
 */

const REVIEW_CLASS = "neos-needs-review";
const REVIEW_TAB_CLASS = "neos-review-tab-indicator";

export function initReviewIndicator() {
    let store = null;

    function findStore() {
        const root = document.getElementById("neos-application");
        if (!root) return null;
        const fiberKey = Object.keys(root).find((k) => k.startsWith("__react"));
        if (!fiberKey) return null;

        const queue = [root[fiberKey]];
        const checked = new WeakSet();

        while (queue.length > 0) {
            const f = queue.shift();
            if (!f || checked.has(f)) continue;
            checked.add(f);

            if (f.memoizedProps?.value?.store?.getState) {
                return f.memoizedProps.value.store;
            }
            if (f.memoizedProps?.store?.getState) {
                return f.memoizedProps.store;
            }

            if (f.child) queue.push(f.child);
            if (f.sibling) queue.push(f.sibling);
        }
        return null;
    }

    function getNodesNeedingReview() {
        if (!store) return new Set();
        const state = store.getState();
        const needsReview = new Set();

        const cr = state?.cr;
        const byContextPath = cr?.nodes?.byContextPath;
        if (!byContextPath) return needsReview;

        for (const [contextPath, nodeData] of Object.entries(byContextPath)) {
            if (nodeData?.properties?.reviewStatus === "needsReview") {
                needsReview.add(contextPath);
            }
        }
        return needsReview;
    }

    function getDocumentNode() {
        if (!store) return null;
        const state = store.getState();
        return state?.cr?.nodes?.documentNode || null;
    }

    function getTreeItemContextPath(el) {
        const fiberKey = Object.keys(el).find((k) => k.startsWith("__react"));
        if (!fiberKey) return null;

        let fiber = el[fiberKey];
        for (let i = 0; i < 15 && fiber; i++) {
            const id = fiber.memoizedProps?.id;
            if (typeof id === "string" && id.startsWith("/sites/")) {
                return id;
            }
            fiber = fiber.return;
        }
        return null;
    }

    function scan() {
        if (!store) {
            store = findStore();
            if (!store) return;
        }

        const needsReview = getNodesNeedingReview();

        // Mark tree nodes
        document.querySelectorAll('a[role="treeitem"]').forEach((link) => {
            const cp = getTreeItemContextPath(link);
            if (cp && needsReview.has(cp)) {
                link.classList.add(REVIEW_CLASS);
            } else {
                link.classList.remove(REVIEW_CLASS);
            }
        });

        // Mark inspector Review tab
        const docNode = getDocumentNode();
        const currentNeedsReview = docNode && needsReview.has(docNode);

        document.querySelectorAll("svg, i").forEach((icon) => {
            if (icon.classList.contains("fa-clipboard-check") ||
                icon.getAttribute("data-icon") === "clipboard-check") {
                const btn = icon.closest("button") || icon.parentElement;
                if (btn) {
                    if (currentNeedsReview) {
                        btn.classList.add(REVIEW_TAB_CLASS);
                    } else {
                        btn.classList.remove(REVIEW_TAB_CLASS);
                    }
                }
            }
        });
    }

    const observer = new MutationObserver(() => requestAnimationFrame(scan));

    function start() {
        const app = document.getElementById("neos-application");
        if (app) {
            observer.observe(app, { childList: true, subtree: true });
            scan();
        } else {
            setTimeout(start, 500);
        }
    }

    start();
    setInterval(scan, 3000);
}
