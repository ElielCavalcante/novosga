<?php
namespace core\model;

/**
 * 
 * @Entity
 * @Table(name="paineis_servicos")
 * 
 * @author rogeriolino
 */ 
 class PainelServico extends Model {
 	
    /**
     * @Id  
     * @ManyToOne(targetEntity="Painel")
     * @JoinColumn(name="host", referencedColumnName="host")
     */
    protected $painel;
    /** 
     * @Id
     * @ManyToOne(targetEntity="Servico")
     * @JoinColumn(name="id_serv", referencedColumnName="id_serv")
     */
    protected $servico;
    /**
     * @ManyToOne(targetEntity="Unidade")
     * @JoinColumn(name="id_uni", referencedColumnName="id_uni")
     */
    protected $unidade;

    public function getPainel() {
        return $this->painel;
    }

    public function setPainel($painel) {
        $this->painel = $painel;
    }

    public function getServico() {
        return $this->servico;
    }

    public function setServico($servico) {
        $this->servico = $servico;
    }

    public function getUnidade() {
        return $this->unidade;
    }

    public function setUnidade($unidade) {
        $this->unidade = $unidade;
    }

}
