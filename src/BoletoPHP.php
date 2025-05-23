<?php
namespace Plotag\Enter8;

/**
 *  Class responsible for creating and communicating with web services for boletos in Brazil
 *
 * @category  library
 * @package   BoletoWebService
 * @license   https://opensource.org/licenses/MIT MIT
 * @author    Guilherme Calabria Filho < guiga86 at gmail dot com >
 * @link      https://github.com/guicalabria/boletophp.git for the canonical source repository
 */


class BoletoPHP {

   private $bancoDisponivel  = array('bancodobrasil');

   private $banco;
   private $bancoWebService;
   /**
    * Mapa das classes dos Bancos e seus c�digos
    *
    * @var array
    */
   protected static $classMap = array(
      '001' => 'BancodoBrasil'
   );

   /*Configura��o dos dados do boleto*/
   private $configuracaoBoleto;

   public $erro = false;
   public $mensagem = '';

   /**
     * Constructor
     * @param string $codigobanco
     */
   public function __construct($codigobanco)
   {
      if (! isset(static::$classMap[$codigobanco]) )
      {
         $this->erro = true;
         $this->mensagem .= '<span class="formok">Banco n�o dispon�vel para uso da classe</span>';
         return false;
      }

      $this->banco = $codigobanco;
   }

   /**
    * Cria a inst�ncia do determinado banco
    *
    * @param array $params Par�metros iniciais para constru��o do objeto
    * @return boolean
    */
   public function loadBank(array $params = array())
   {
      if (! isset(static::$classMap[$this->banco]) )
      {
         $this->erro = true;
         $this->mensagem .= '<span class="formok">Banco n�o dispon�vel para uso da classe</span>';
         return false;
      }
      $class = __NAMESPACE__ . '\\' .static::$classMap[$this->banco].'Web';

      $this->bancoWebService = new $class($params);

      return true;
   }
   /**
    * Retorna a inst�ncia de um Banco atrav�s do c�digo
    *
    * @param array $params Par�metros iniciais para constru��o do objeto
    * @return boolean
    */
   public function loadSetup(array $params = array())
   {
      $this->loadBank($params);

      if ( $this->erro )
      {
         $this->mensagem .= '<span class="formok">Erro ao carregar configura��o</span>';
         return false;
      }
      if ( isset($params['obtertoken']) && (int)$params['obtertoken']===1 ) $this->bancoWebService->obterToken(true);
      else $this->bancoWebService->obterToken(false);
      if ( $this->bancoWebService->erro )
      {
         $this->erro = true;
         $this->mensagem .= '<span class="formok">Erro ao carregar token</span>'.$this->bancoWebService->mensagem;
         return false;
      }
      return true;
   }

   /**
    * Define a configura��o do boleto
    *
    * @param array $configuracao Dados do registro
    * @return boolean
    */
   public function setConfiguracao(array $configuracao)
   {
      $this->configuracaoBoleto = $configuracao;
      return true;
   }

   /**
    * Cria o registro do boleto
    *
    * @param array $registro Dados do registro
    * @return boolean
    */
   public function novoRegistro(array $registro)
   {
      $parametros = array('codigoModalidadeTitulo'=>1,'indicadorPermissaoRecebimentoParcial'=>'N','codigoTipoCanalSolicitacao'=>5,
        'textoDescricaoTipoTitulo'=>'Recibo','codigoChaveUsuario'=>1,'resultado'=>array());

      if ( $registro['especietitulo']=='dm' ) $parametros['codigoTipoTitulo'] = 2;
      elseif ( $registro['especietitulo']=='ds' ) $parametros['codigoTipoTitulo'] = 4;
      else $parametros['codigoTipoTitulo'] = 17;

      $coluna = array('numeroConvenio'=>'conveniolider','numeroCarteira'=>'carteira','numeroVariacaoCarteira'=>'variacaodacarteira','codigoAceiteTitulo'=>'aceite');

      foreach($coluna as $key=>$item)
      {
         if ( !isset($this->configuracaoBoleto[$item]) || $this->configuracaoBoleto[$item]=='' )
         {
            $this->erro = true;
            $this->mensagem .= '<span class="formok">Campo ('.$item.') inv�lido</span>';
            return false;
         }
         $parametros[$key] = $this->configuracaoBoleto[$item];
      }

      $coluna = array('dataEmissaoTitulo'=>'dataregistro','dataVencimentoTitulo'=>'vencimento','valorOriginalTitulo'=>'valor','numeroInscricaoPagador'=>'cnpj','nomePagador'=>'razaosocial','textoEnderecoPagador'=>'endereco','numeroCepPagador'=>'cep',
         'nomeMunicipioPagador'=>'cidade','nomeBairroPagador'=>'bairro','siglaUfPagador'=>'estado','textoNumeroTelefonePagador'=>'telefone');
      $tamanhoCampo = array('nomePagador'=>60,'nomeMunicipioPagador'=>20,'nomeBairroPagador'=>20,'textoEnderecoPagador'=>60,'textoNumeroTelefonePaga'=>10);
      foreach($coluna as $key=>$item)
      {
         if ( !isset($registro[$item]) || $registro[$item]=='' )
         {
            $this->erro = true;
            $this->mensagem .= '<span class="formok">Campo ('.$item.') inv�lido para registro do boleto</span>';
            return false;
         }
         $parametros[$key] = utf8_encode(Texto::retiraAcento($registro[$item]));
         if ( isset($tamanhoCampo[$key]) ) $parametros[$key] = substr($parametros[$key],0,$tamanhoCampo[$key]);
      }

      $parametros['textoMensagemBloquetoOcorrencia'] = (isset($registro['mensagem'])?utf8_encode($registro['mensagem']):'');

      /*Nosso n�mero*/
      $parametros['textoNumeroTituloCliente'] = '000'.$parametros['numeroConvenio'];
      if ( $parametros['numeroCarteira']=='17' )
      {
         /*FORMATO NOSSO N�MERO PARA CONV�NIOS ACIMA DE 1.000.000 (UM MILH�O): A composi��o do nosso n�mero deve obedecer as seguintes regras:
            CCCCCCCNNNNNNNNNN conv�nios com numera��o acima de 1.000.000, onde:
            "C" - � o n�mero do conv�nio fornecido pelo Banco (n�mero fixo e n�o pode ser
            alterado)
            "N" - � um sequencial atribu�do pelo cliente*/
         /*Utilizar a duplicata como nosso n�mero, como a duplicata pode ter texto usar:
            O primeiro algarimos para diferenciar as notas
            O segundo se � parcela (0 n�o �), diferente at� o quarto o n�mero da parcela*/
         /*hif�n - parcela, barra - uni�o, Ped - pedido*/

         $nossonumero = '';
         /*Tipo de duplicata. 0-Uni�o,1-Pedido,2-mercantil,3-servi�o,4-Cr�dito*/
         if ( strpos($registro['duplicata'],'/')===false ) $nossonumero .= '0';
         elseif ( strpos($registro['duplicata'],'PED')!==false ) $nossonumero .= '1';
         elseif ( strpos($registro['duplicata'],'CRED')!==false ) $nossonumero .= '4';
         elseif ( $registro['especietitulo']=='dm' ) $nossonumero .= '2';
         elseif ( $registro['especietitulo']=='ds' ) $nossonumero .= '3';
         else throw new \Exception('Tipo de duplicata n�o encontrada, opera��o cancelada');

         if ( strpos($registro['duplicata'],'/')===false )
         {
            if ( strpos($registro['duplicata'],'-')===false )
            {
               $nossonumero .= '00';
               $nossonumero .= str_pad(Texto::somenteNumero($registro['duplicata']),7,'0',STR_PAD_LEFT);
            }
            else
            {
               $aux = explode('-',$registro['duplicata']);
               $nossonumero .= str_pad(Texto::somenteNumero($aux[1]),2,'0',STR_PAD_LEFT);
               $nossonumero .= str_pad(Texto::somenteNumero($aux[0]),7,'0',STR_PAD_LEFT);
            }
         }
         else
         {
            $nossonumero .= '00';
            $aux = explode('/',$registro['duplicata']);
            if ( strpos($aux[0],'-')===false ) $nossonumero .= str_pad(Texto::somenteNumero($aux[0]),7,'0',STR_PAD_LEFT);
            else
            {
               $aux2 = explode('-',$aux[0]);
               $nossonumero .= str_pad(Texto::somenteNumero($aux2[1]),2,'0',STR_PAD_LEFT);
               $nossonumero .= str_pad(Texto::somenteNumero($aux2[0]),7,'0',STR_PAD_LEFT);
            }
         }
         $parametros['textoNumeroTituloCliente'] .= $nossonumero;
      }
      else
      {
         $this->erro = true;
         $this->mensagem .= '<span class="formok">Carteira n�o implementada</span>';
         return false;
      }
      $parametros['textoNumeroTituloBeneficiario'] = $registro['duplicata'];
      /*1 = CPF-PAGADOR 2 = CNPJ-PAGADOR*/
      $parametros['codigoTipoInscricaoPagador'] = (strlen($parametros['numeroInscricaoPagador'])==11 ? 1:2);

      /*Dados para boleto banc�rios registrado*/
      $dadosRegistro = array('sid'=>$registro['sid'],'banco' => $this->banco,'dataemissao'=>$registro['dataregistro'],'duplicata'=>$registro['duplicata'],'vencimento'=>$registro['vencimento'],'nossonumero'=>$nossonumero,'digitonossonumero'=>'','especietitulo'=>$registro['especietitulo'],'tipodecobranca'=>1,'valorlancamento'=>$registro['valor'],'carteira'=>$parametros['numeroCarteira']);

      /*codigoTipoDesconto 0 = SEM-DESCONTO 1 = DESCONTO-VALOR 2 = DESCONTO-PERCENTUAL 3 = POR-DIA-ANTECIPACAO*/
      $parametros['dataDescontoTitulo'] = false;
      $parametros['valorDescontoTitulo'] = false;
      $parametros['percentualDescontoTitulo'] = false;
      if ( isset($this->configuracaoBoleto['descontopordia']) && $this->configuracaoBoleto['descontopordia']!='' )
      {
         $parametros['codigoTipoDesconto'] = 3;
         $parametros['valorDescontoTitulo'] = str_replace(',','.',$this->configuracaoBoleto['descontopordia']);
         $dadosRegistro['descontoconcedido'] = $parametros['valorDescontoTitulo'];
      }
      elseif ( isset($this->configuracaoBoleto['descontopercentual']) && $this->configuracaoBoleto['descontopercentual']!='' )
      {
         $parametros['codigoTipoDesconto'] = 2;
         $parametros['dataDescontoTitulo'] = $parametros['dataVencimentoTitulo'];
         $parametros['percentualDescontoTitulo'] = str_replace(',','.',$this->configuracaoBoleto['descontopercentual']);
         $dadosRegistro['descontoconcedido'] = $registro['duplicata']*$parametros['percentualDescontoTitulo']/100;
      }
      else $parametros['codigoTipoDesconto'] = 0;

      $parametros['dataMultaTitulo'] = false;
      $parametros['percentualMultaTitulo'] = false;
      $parametros['valorMultaTitulo'] = false;
      /*codigoTipoMulta 0 = Sem multa, 1 = Valor da multa, 2 = Percentual da multa*/
      if ( isset($this->configuracaoBoleto['multafixo']) && $this->configuracaoBoleto['multafixo']!='' )
      {
         $parametros['codigoTipoMulta'] = 1;
         $parametros['valorMultaTitulo'] = str_replace(',','.',$this->configuracaoBoleto['multafixo']);
         if ( !isset($registro['datamulta']) )
         {
            $this->erro = true;
            $this->mensagem .= '<span class="formok">Data para multa n�o encontrado</span>';
            return false;
         }
         $parametros['dataMultaTitulo'] = $registro['datamulta'];
      }
      elseif ( isset($this->configuracaoBoleto['multapercentual']) && $this->configuracaoBoleto['multapercentual']!='' )
      {
         $parametros['codigoTipoMulta'] = 2;
         $parametros['percentualMultaTitulo'] = $this->configuracaoBoleto['multapercentual'];
         if ( !isset($registro['datamulta']) )
         {
            $this->erro = true;
            $this->mensagem .= '<span class="formok">Data para multa n�o encontrado</span>';
            return false;
         }
         $parametros['dataMultaTitulo'] = $registro['datamulta'];
      }
      else $parametros['codigoTipoMulta'] = 0;

      if ( isset($this->configuracaoBoleto['abatimento']) && $this->configuracaoBoleto['abatimento']!='' )
      {
         $parametros['valorAbatimentoTitulo'] = $this->configuracaoBoleto['abatimento'];
         $dadosRegistro['abatimento'] = $parametros['valorAbatimentoTitulo'];
      }
      else  $parametros['valorAbatimentoTitulo'] = false;

      if ( isset($this->configuracaoBoleto['protestodiasuteis']) && $this->configuracaoBoleto['protestodiasuteis']!='' ) $parametros['quantidadeDiaProtesto'] = $this->configuracaoBoleto['protestodiasuteis'];
      elseif ( isset($this->configuracaoBoleto['protestodiascorridos']) && $this->configuracaoBoleto['protestodiascorridos']!='' ) $parametros['quantidadeDiaProtesto'] = $this->configuracaoBoleto['protestodiascorridos'];
      else  $parametros['quantidadeDiaProtesto'] = false;

      $parametros['percentualJuroMoraTitulo'] = false;
      $parametros['valorJuroMoraTitulo'] = false;
      /*codigoTipoJuroMora 0 = Nao informado 1 = Valor Por Dia De Atraso 2 = Taxa Mensal 3 = Isento*/
      if ( isset($this->configuracaoBoleto['jurospordia']) && $this->configuracaoBoleto['jurospordia']!='' )
      {
         $parametros['codigoTipoJuroMora'] = 1;
         $parametros['valorJuroMoraTitulo'] = $this->configuracaoBoleto['jurospordia'];
         $dadosRegistro['jurosdemora'] = str_replace(',','.',$parametros['valorJuroMoraTitulo']);
      }
      elseif ( isset($this->configuracaoBoleto['jurospercentual']) && $this->configuracaoBoleto['jurospercentual']!='' )
      {
         $parametros['codigoTipoJuroMora'] = 2;
         $parametros['percentualJuroMoraTitulo'] = str_replace(',','.',$this->configuracaoBoleto['jurospercentual']);
         $dadosRegistro['jurospercentual'] = str_replace(',','.',$parametros['percentualJuroMoraTitulo']);
      }
      else $parametros['codigoTipoJuroMora'] = 0;

      /*Corrigindo a ordem dos campos*/
      $colunas = array('numeroConvenio','numeroCarteira','numeroVariacaoCarteira','codigoModalidadeTitulo','dataEmissaoTitulo','dataVencimentoTitulo','valorOriginalTitulo','codigoTipoDesconto','dataDescontoTitulo','percentualDescontoTitulo','valorDescontoTitulo','valorAbatimentoTitulo','quantidadeDiaProtesto','codigoTipoJuroMora','valorJuroMoraTitulo','percentualJuroMoraTitulo','codigoTipoMulta','dataMultaTitulo','percentualMultaTitulo','valorMultaTitulo','codigoAceiteTitulo','codigoTipoTitulo','textoDescricaoTipoTitulo','indicadorPermissaoRecebimentoParcial','textoNumeroTituloBeneficiario','textoNumeroTituloCliente','textoMensagemBloquetoOcorrencia','codigoTipoInscricaoPagador','numeroInscricaoPagador','nomePagador','textoEnderecoPagador','numeroCepPagador','nomeMunicipioPagador','nomeBairroPagador','siglaUfPagador','textoNumeroTelefonePagador','codigoChaveUsuario','codigoTipoCanalSolicitacao');
      $novosParametros = array();
      foreach($colunas as $nome)
      {
         if ( !isset($parametros[$nome]) )
         {
            $this->erro = true;
            $this->mensagem .= '<span class="formok">Campo ('.$nome.') n�o encontrado para registro do boleto</span>';
            return false;
         }
         if ( $parametros[$nome]===false ) continue;

         $novosParametros[$nome] = trim($parametros[$nome]);
      }
      //$this->bancoWebService->alterarParaAmbienteDeTestes();
      // $bb->alterarParaAmbienteDeProducao();

      // Exemplo de chamada passando os par�metros com a token.
      // Retorna um array com a resposta do Banco do Brasil, se ocorreu tudo bem. Caso contr�rio, retorna "false".
      // A descri��o do erro pode ser obtida pelo m�todo "obterErro()".

      $resultado = $this->bancoWebService->registrarBoleto($novosParametros);
      if ( $this->bancoWebService->erro ){ $this->erro = true;$this->mensagem .= '<span class="formok">Erro ao registrar boleto</span>'.utf8_decode($this->bancoWebService->mensagem);return false; }

      $dadosRegistro['agenciarecebedora'] = $resultado['codigoPrefixoDependenciaBeneficiario'];
      $dadosRegistro['contarecebedora'] = $resultado['numeroContaCorrenteBeneficiario'];
      $dadosRegistro['linhadigitavel'] = $resultado['linhaDigitavel'];
      $dadosRegistro['codigobarra'] = $resultado['codigoBarraNumerico'];
      $dadosRegistro['resultado'] = $resultado;

      return $dadosRegistro;
   }
}
