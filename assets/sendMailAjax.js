$(document).on('click','a.popup-sendmailajax',function(event){
  event.preventDefault();
  // Start by save settings
  /*
  delay=$("#plugin\\[sendMailAjax\\]\\[mindelay\\]").val();
  maxmail=$("#plugin\\[sendMailAjax\\]\\[maxemail\\]").val();
  */
  dialogName = '#dialogsendmailajax';
  contentUrl=$(this).attr('href');
  dialogTitle=$(this).text();
  $('#sendmailajax').dialog('destroy').remove();
  
    $("<div id='sendmailajax'>").dialog({
        modal: true,
        open: function ()
        {
            $(this).load(contentUrl);
        },
        title: dialogTitle,
        dialogClass: "dialog-sendmailajax",
        buttons: { Cancel: function() { $(this).dialog("close"); } },
        close: function () {
            $(this).remove();
        }
    });
});
$(document).on('click','a#launch-email',function(event){
  var jsonurl=$(this).attr('rel');
  $(this).closest('li').text($(this).text());
  loopSendEmail(jsonurl,0);
});
/*
* Used to update response one by one
* @param {string} jsonurl : The json Url to request
* @param {integer} tokenid : The token id
*/
function loopSendEmail(jsonurl,tokenid) {
  
  if($("#sendmailajax").length>0)// Don't send if user click on 'Cancel'
  {
    $.ajax({
      url: jsonurl,
      dataType : 'json',
        data : {'tokenid': tokenid},
        success: function (data) {
          $("#sendmailajax .sendmailajax-list").prepend("<li style='display:none'>"+data.message+"</li>");
          $("#sendmailajax .sendmailajax-list :first-child").slideDown(500);
          //$("#sendmailajax .sendmailajax-list :nth-child(6)").slideUp(500,function() { });
            if (data.next) {
                loopSendEmail(jsonurl,data.next);
            } else {
              $("#sendmailajax").closest(".ui-dialog").find(" .ui-dialog-buttonset .ui-button-text").html("Done");
              $("#sendmailajax .sendmailajax-list").prepend("<li><strong>Done</strong></li>");
            }
        },
    });
  }
}
