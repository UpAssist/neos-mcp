import manifest from "@neos-project/neos-ui-extensibility";
import React from "react";

const PACKAGE = "UpAssist.Neos.Mcp";
const XLIFF_SOURCE = PACKAGE + ":NodeTypes.Mixin.ReviewStatus";

const typeToTranslationId = {
    propertyChanged: XLIFF_SOURCE + ":changelog.propertyChanged",
    elementAdded: XLIFF_SOURCE + ":changelog.elementAdded",
    elementMoved: XLIFF_SOURCE + ":changelog.elementMoved",
    elementRemoved: XLIFF_SOURCE + ":changelog.elementRemoved",
};

const typeToFallback = {
    propertyChanged: "Property '{0}' changed",
    elementAdded: "New element added",
    elementMoved: "Element moved",
    elementRemoved: "Element removed",
};

function ChangelogEditor({ value, i18nRegistry }) {
    let entries = [];
    try {
        const parsed = JSON.parse(value || "[]");
        if (Array.isArray(parsed)) {
            entries = parsed;
        }
    } catch (e) {
        // Legacy plain-text changelog — show as-is
        if (typeof value === "string" && value.trim()) {
            return <pre style={styles.legacy}>{value}</pre>;
        }
    }

    if (entries.length === 0) {
        const emptyLabel = i18nRegistry.translate(
            XLIFF_SOURCE + ":changelog.empty",
            "No changes recorded"
        );
        return <div style={styles.empty}>{emptyLabel}</div>;
    }

    return (
        <div style={styles.container}>
            {entries.map((entry, index) => (
                <div key={index} style={styles.entry}>
                    <span style={styles.date}>{entry.date}</span>
                    <span style={styles.description}>
                        {renderDescription(entry, i18nRegistry)}
                    </span>
                </div>
            ))}
        </div>
    );
}

function renderDescription(entry, i18nRegistry) {
    const translationId = typeToTranslationId[entry.type];
    const fallback = typeToFallback[entry.type];

    if (!translationId) {
        return entry.description || entry.type || "Unknown change";
    }

    if (entry.type === "propertyChanged") {
        const propertyLabel = entry.labelId
            ? i18nRegistry.translate(entry.labelId, entry.propertyName)
            : entry.propertyName;

        const template = i18nRegistry.translate(translationId, fallback);
        return template.replace("{0}", propertyLabel);
    }

    return i18nRegistry.translate(translationId, fallback);
}

const styles = {
    container: {
        maxHeight: "300px",
        overflowY: "auto",
        fontSize: "13px",
        lineHeight: "1.5",
    },
    entry: {
        display: "flex",
        gap: "8px",
        padding: "4px 0",
        borderBottom: "1px solid rgba(255,255,255,0.1)",
    },
    date: {
        color: "rgba(255,255,255,0.5)",
        whiteSpace: "nowrap",
        flexShrink: 0,
    },
    description: {
        color: "rgba(255,255,255,0.9)",
    },
    empty: {
        color: "rgba(255,255,255,0.4)",
        fontStyle: "italic",
        fontSize: "13px",
        padding: "8px 0",
    },
    legacy: {
        whiteSpace: "pre-wrap",
        fontSize: "12px",
        color: "rgba(255,255,255,0.7)",
        margin: 0,
        maxHeight: "300px",
        overflowY: "auto",
    },
};

manifest(PACKAGE + ":ChangelogEditor", {}, (globalRegistry) => {
    const editorsRegistry = globalRegistry
        .get("inspector")
        .get("editors");

    editorsRegistry.set(PACKAGE + "/Inspector/Editors/ChangelogEditor", {
        component: ChangelogEditor,
    });
});
