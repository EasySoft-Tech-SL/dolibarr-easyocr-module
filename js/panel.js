
let valoresSeleccionados = [];

$("#all_options").val(todosValoresSeleccionados);

$("#options").val(valoresSeleccionadosString);

if(todosValoresSeleccionados=="si"){

    $("#all-options").prop("checked", true);

    $(".options").prop("disabled",true);
      
    $(".options").prop("checked", true);

    $("#block-actions").css("display","block");
}


if(valoresSeleccionadosString){

    valoresSeleccionados=valoresSeleccionadosString.split(",");
}


if(valoresSeleccionados.length>0){

    $("input[type=checkbox].options").each(function() {

        const valorCheckbox = $(this).val(); 
    
        if (valoresSeleccionados.includes(valorCheckbox)) {
            $(this).prop("checked", true);
        }
    });


    $("#block-actions").css("display","block");

}


function newPage(page){

    $("#page").val(page);

    $( "#pagination" ).trigger( "submit" );

}


function search(){

    $("#all_options").val("no");

    $("#options").val("");

    newPage(1);
}


function confirm_action(){

    $("#confirm_action").val("delete");

    newPage(1);
}


$("#limit-options").on( "change", function() {
    
    var option=$(this).val();

    $("#limit").val(option);

    newPage(1);

});


$("#actions").on( "change", function() {
    
    var option=$(this).val();

    if(option){

        $("#confirm").css("display","inline-block");

    }else{

        $("#confirm").css("display","none");
    }
});


$("#all-options").change(function() {

    const valor = $(this).val();

    valoresSeleccionados = [];

    $("#options").val("");

    if ($(this).is(":checked")) {

        $("#all_options").val("si");

        $(".options").prop("disabled",true);
      
        $(".options").prop("checked", true);

        $("#block-actions").css("display","block");

    } else {

        $("#all_options").val("no");

        $(".options").prop("disabled",false);

        $(".options").prop("checked", false);

        $("#block-actions").css("display","none");

        $("#actions").val("");

        $("#confirm").css("display","none");
    }

});



$(".options").change(function() {
  const valor = $(this).val();

  if ($(this).is(":checked")) {
    
    valoresSeleccionados.push(valor);

  } else {

    const index = valoresSeleccionados.indexOf(valor);
    
    if (index !== -1) {
      valoresSeleccionados.splice(index, 1);
    }
  }

  $("#options").val(valoresSeleccionados.toString());

  if(valoresSeleccionados.length>0){

    $("#block-actions").css("display","block");

  }else{

    $("#block-actions").css("display","none");

    $("#actions").val("");

    $("#confirm").css("display","none");
  }

});
