(function () {
  if (!window.cdair) {
    return;
  }

  var busy = false;

  function post(action, id) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", window.cdair.nonce);
    if (id) {
      body.append("attachment_id", id);
    }
    return fetch(window.cdair.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    }).then(function (response) {
      return response.text();
    }).then(function (text) {
      try {
        return JSON.parse(text);
      } catch (error) {
        return { success: false, data: { message: window.cdair.failed } };
      }
    });
  }

  function messageOf(result) {
    if (result && result.data && result.data.message) {
      return result.data.message;
    }
    return window.cdair.failed;
  }

  function poll(id, attempt) {
    if (attempt > 40) {
      return Promise.resolve({
        success: false,
        data: { message: window.cdair.timeout },
      });
    }
    return post("cdair_poll", id).then(function (result) {
      if (!result || !result.success) {
        return result;
      }
      if (result.data && result.data.status === "processing") {
        return new Promise(function (resolve) {
          window.setTimeout(resolve, 3000);
        }).then(function () {
          return poll(id, attempt + 1);
        });
      }
      return result;
    });
  }

  function finish(button, label) {
    busy = false;
    if (!button) {
      return;
    }
    button.removeAttribute("aria-disabled");
    if (button.tagName === "BUTTON") {
      button.disabled = false;
    }
    button.textContent = label;
  }

  document.addEventListener("click", function (event) {
    var test = event.target.closest("#cdair-test");
    if (test) {
      event.preventDefault();
      var resultBox = document.getElementById("cdair-test-result");
      test.disabled = true;
      if (resultBox) {
        resultBox.textContent = window.cdair.testing;
      }
      post("cdair_test").then(function (result) {
        if (resultBox) {
          resultBox.textContent = messageOf(result);
        }
        test.disabled = false;
      });
      return;
    }

    var button = event.target.closest("[data-cdair-action]");
    if (!button || busy) {
      return;
    }
    event.preventDefault();

    var action = button.getAttribute("data-cdair-action");
    var id = button.getAttribute("data-id");
    var confirmText = action === "cdair_restore" ? window.cdair.confirmRestore : window.cdair.confirmReplace;
    if (!window.confirm(confirmText)) {
      return;
    }

    var label = button.textContent;
    busy = true;
    button.setAttribute("aria-disabled", "true");
    if (button.tagName === "BUTTON") {
      button.disabled = true;
    }
    button.textContent = action === "cdair_restore" ? window.cdair.restoring : window.cdair.working;

    var request = action === "cdair_restore" ? post("cdair_restore", id) : post("cdair_start", id).then(function (result) {
      if (!result || !result.success) {
        return result;
      }
      return poll(id, 0);
    });

    request.then(function (result) {
      if (!result || !result.success) {
        window.alert(messageOf(result));
        finish(button, label);
        return;
      }
      window.alert(messageOf(result));
      window.location.reload();
    }).catch(function () {
      window.alert(window.cdair.failed);
      finish(button, label);
    });
  });
})();
