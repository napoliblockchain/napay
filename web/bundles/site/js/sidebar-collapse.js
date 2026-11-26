$("#pushmenu_collapsed").click(function () {
    $.ajax({
        url: yiiOptions.changeCollapsed,
        type: 'POST',
        data: { [yii.getCsrfParam()]: yii.getCsrfToken() },
        success: function () {
            console.log('riavvio...');
            location.reload(true);
        }
    });
});
