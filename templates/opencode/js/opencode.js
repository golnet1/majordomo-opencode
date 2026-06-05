function ocGetBaseUrl() {
    return '/modules/opencode/opencode_ajax.php';
}

function ocPollMessage(msgId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ocGetBaseUrl() + '?op=check_message', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status == 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && !res.processing) {
                    ocHideTyping();
                    ocAddMessage('assistant', res.response);
                } else if (res.success && res.processing) {
                    setTimeout(function() { ocPollMessage(msgId); }, 2000);
                } else {
                    ocHideTyping();
                    var err = res.error || 'Unknown error';
                    ocAddMessage('assistant', 'Error: ' + err);
                }
            } catch(e) {
                ocHideTyping();
                ocAddMessage('assistant', 'Invalid response from server');
            }
        } else {
            ocHideTyping();
            ocAddMessage('assistant', 'Connection error. Please try again.');
        }
    };
    xhr.onerror = function() {
        ocHideTyping();
        ocAddMessage('assistant', 'Connection error. Please try again.');
    };
    xhr.send('message_id=' + msgId);
}

function ocSendMessage() {
    var input = document.getElementById('ocInput');
    var msg = input.value.trim();
    if (!msg) return false;

    input.value = '';
    ocAddMessage('user', msg);
    ocShowTyping();

    var btn = document.getElementById('ocSendBtn');
    btn.disabled = true;
    var typingText = ocLang && ocLang.typing || '...';
    btn.textContent = typingText;

    var sendText = ocLang && ocLang.send || 'Send';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ocGetBaseUrl() + '?op=send_message', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = sendText;
        if (xhr.status == 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.processing) {
                    ocPollMessage(res.message_id);
                } else if (res.success && res.response) {
                    ocHideTyping();
                    ocAddMessage('assistant', res.response);
                } else {
                    ocHideTyping();
                    var errPrefix = res.error ? 'Error: ' : '';
                    var unknown = ocLang && ocLang.unknownError || 'Unknown error';
                    ocAddMessage('assistant', errPrefix + (res.error || unknown));
                }
            } catch(e) {
                ocHideTyping();
                var serverErr = ocLang && ocLang.serverError || 'Invalid response from server';
                ocAddMessage('assistant', serverErr);
            }
        } else {
            ocHideTyping();
            var connErr = ocLang && ocLang.connectError || 'Connection error. Please try again.';
            ocAddMessage('assistant', connErr);
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = sendText;
        ocHideTyping();
        var connErr = ocLang && ocLang.connectError || 'Connection error. Please try again.';
        ocAddMessage('assistant', connErr);
    };
    xhr.send('message=' + encodeURIComponent(msg));

    return false;
}

function ocAddMessage(role, text) {
    var container = document.getElementById('ocMessages');
    var div = document.createElement('div');
    div.className = 'oc-message oc-message-' + role;

    var avatar = document.createElement('div');
    avatar.className = 'oc-message-avatar';
    avatar.innerHTML = role === 'user'
        ? '<i class="glyphicon glyphicon-user"></i>'
        : '<i class="glyphicon glyphicon-cloud"></i>';

    var content = document.createElement('div');
    content.className = 'oc-message-content';

    var textDiv = document.createElement('div');
    textDiv.className = 'oc-message-text';
    textDiv.textContent = text;

    content.appendChild(textDiv);
    div.appendChild(avatar);
    div.appendChild(content);
    container.appendChild(div);

    ocScrollToBottom();
}

function ocShowTyping() {
    var container = document.getElementById('ocMessages');
    var div = document.createElement('div');
    div.className = 'oc-message oc-message-assistant';
    div.id = 'ocTypingIndicator';

    var avatar = document.createElement('div');
    avatar.className = 'oc-message-avatar';
    avatar.innerHTML = '<i class="glyphicon glyphicon-cloud"></i>';

    var content = document.createElement('div');
    content.className = 'oc-message-content';
    content.innerHTML = '<div class="oc-typing"><span></span><span></span><span></span></div>';

    div.appendChild(avatar);
    div.appendChild(content);
    container.appendChild(div);
    ocScrollToBottom();
}

function ocHideTyping() {
    var typing = document.getElementById('ocTypingIndicator');
    if (typing) typing.remove();
}

function ocScrollToBottom() {
    var container = document.getElementById('ocMessages');
    container.scrollTop = container.scrollHeight;
}

function ocClearHistory() {
    var confirmMsg = ocLang && ocLang.clearConfirm || 'Clear chat history?';
    if (!confirm(confirmMsg)) return;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', ocGetBaseUrl() + '?op=clear_history', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status == 200) {
            document.getElementById('ocMessages').innerHTML = '';
            var msg = ocLang && ocLang.historyCleared || 'History cleared. How can I help you?';
            ocAddMessage('assistant', msg);
        }
    };
    xhr.send();
}

function ocLoadHistory() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ocGetBaseUrl() + '?op=load_history', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status == 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success && res.messages) {
                    var container = document.getElementById('ocMessages');
                    container.innerHTML = '';
                    for (var i = 0; i < res.messages.length; i++) {
                        var m = res.messages[i];
                        ocAddMessage(m.ROLE, m.MESSAGE);
                    }
                    if (res.messages.length === 0) {
                        var msg = ocLang && ocLang.howCanIHelp || 'How can I help you?';
                        ocAddMessage('assistant', msg);
                    }
                }
            } catch(e) {}
        }
    };
    xhr.send();
}

jQuery(function() {
    ocLoadHistory();
    var input = document.getElementById('ocInput');
    if (input) input.focus();
});

