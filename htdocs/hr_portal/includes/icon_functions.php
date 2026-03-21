<?php
// File: includes/icon_functions.php
// Offline icon system using emojis - works without internet

function getIconCSS() {
    return '
    <style>
        /* Simple emoji icon system - works completely offline */
        [class^="icon-"]:before, [class*=" icon-"]:before {
            display: inline-block;
            margin-right: 5px;
            font-family: "Segoe UI Emoji", "Apple Color Emoji", "Noto Color Emoji", "EmojiOne Color", "Twemoji Mozilla", "Android Emoji", sans-serif;
            font-style: normal;
            font-weight: normal;
            font-variant: normal;
            text-transform: none;
            line-height: 1;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        /* Navigation Icons */
        .icon-dashboard:before { content: "📊 "; }
        .icon-home:before { content: "🏠 "; }
        .icon-user:before { content: "👤 "; }
        .icon-users:before { content: "👥 "; }
        .icon-edit:before { content: "✏️ "; }
        .icon-delete:before { content: "🗑️ "; }
        .icon-save:before { content: "💾 "; }
        .icon-cancel:before { content: "❌ "; }
        .icon-check:before { content: "✅ "; }
        .icon-calendar:before { content: "📅 "; }
        .icon-clock:before { content: "⏰ "; }
        .icon-leave:before { content: "🏖️ "; }
        .icon-key:before { content: "🔑 "; }
        .icon-logout:before { content: "🚪 "; }
        .icon-settings:before { content: "⚙️ "; }
        .icon-error:before { content: "⚠️ "; }
        .icon-success:before { content: "✅ "; }
        .icon-info:before { content: "ℹ️ "; }
        .icon-hr:before { content: "🏢 "; }
        .icon-admin:before { content: "👑 "; }
        .icon-dm:before { content: "📋 "; }
        .icon-mg:before { content: "👔 "; }
        .icon-employee:before { content: "👤 "; }
        .icon-excel:before { content: "📤 "; }
        .icon-timesheet:before { content: "📝 "; }
        .icon-attendance:before { content: "📋 "; }
        .icon-sick:before { content: "🤒 "; }
        .icon-casual:before { content: "☕ "; }
        .icon-lop:before { content: "💰 "; }
        .icon-history:before { content: "📜 "; }
        .icon-view:before { content: "👁️ "; }
        .icon-balance:before { content: "⚖️ "; }
        .icon-hourglass:before { content: "⏳ "; }
        .icon-sync:before { content: "🔄 "; }
        .icon-login:before { content: "🔓 "; }
        .icon-shield:before { content: "🛡️ "; }
        .icon-plus:before { content: "➕ "; }
        .icon-minus:before { content: "➖ "; }
        .icon-search:before { content: "🔍 "; }
        .icon-export:before { content: "📤 "; }
        .icon-import:before { content: "📥 "; }
        .icon-arrow-left:before { content: "← "; }
        .icon-arrow-right:before { content: "→ "; }
        .icon-arrow-up:before { content: "↑ "; }
        .icon-arrow-down:before { content: "↓ "; }
        .icon-file:before { content: "📄 "; }
        .icon-folder:before { content: "📁 "; }
        .icon-download:before { content: "⬇️ "; }
        .icon-upload:before { content: "⬆️ "; }
        .icon-print:before { content: "🖨️ "; }
        .icon-email:before { content: "✉️ "; }
        .icon-phone:before { content: "📞 "; }
        .icon-location:before { content: "📍 "; }
        .icon-warning:before { content: "⚠️ "; }
        .icon-question:before { content: "❓ "; }
        .icon-database:before { content: "🗄️ "; }
        .icon-project:before { content: "📊 "; }
        .icon-task:before { content: "✅ "; }
        .icon-software:before { content: "💻 "; }
        .icon-module:before { content: "📦 "; }
        .icon-stop:before { content: "⏹️ "; }
        .icon-play:before { content: "▶️ "; }
        .icon-check-circle:before { content: "✅ "; }
        .icon-exclamation-circle:before { content: "⚠️ "; }
        .icon-exclamation-triangle:before { content: "⚠️ "; }
        .icon-info-circle:before { content: "ℹ️ "; }
        .icon-times-circle:before { content: "❌ "; }
        .icon-list:before { content: "📋 "; }
        .icon-folder-open:before { content: "📂 "; }
        .icon-tasks:before { content: "📋 "; }
        .icon-check-square:before { content: "✅ "; }
        .icon-chart-line:before { content: "📈 "; }
        .icon-user-plus:before { content: "👥➕ "; }
        .icon-user-cog:before { content: "👤⚙️ "; }
        .icon-user-tie:before { content: "👔 "; }
        .icon-cog:before { content: "⚙️ "; }
        .icon-trash:before { content: "🗑️ "; }
        .icon-ban:before { content: "🚫 "; }
        .icon-bomb:before { content: "💣 "; }
        .icon-tools:before { content: "🛠️ "; }
        .icon-clipboard-list:before { content: "📋 "; }
        .icon-hdd:before { content: "💾 "; }
        .icon-filter:before { content: "🔍 "; }
        .icon-eye:before { content: "👁️ "; }
        .icon-save:before { content: "💾 "; }
        
        /* Button icons */
        .btn i[class^="icon-"], 
        .btn-small i[class^="icon-"],
        button i[class^="icon-"] {
            font-size: 1.1em;
            vertical-align: middle;
            margin-right: 4px;
        }
        
        /* Sidebar icons */
        .sidebar-nav i[class^="icon-"] {
            width: 20px;
            text-align: center;
            font-size: 1.2em;
        }
        
        /* Card header icons */
        .card-title i[class^="icon-"] {
            margin-right: 8px;
            color: #006400;
        }
        
        /* Stat card icons */
        .stat-icon i[class^="icon-"] {
            font-size: 24px;
        }
        
        /* Page title icon */
        .page-title i[class^="icon-"] {
            margin-right: 10px;
            color: #006400;
        }
        
        /* Alert icons */
        .alert i[class^="icon-"] {
            margin-right: 8px;
            font-size: 1.2em;
        }
        
        /* Status badge icons */
        .status-badge i[class^="icon-"] {
            margin-right: 3px;
            font-size: 0.9em;
        }
        
        /* Remove margin for pure icon buttons */
        .btn-icon-only i[class^="icon-"] {
            margin-right: 0;
        }
    </style>';
}
?>