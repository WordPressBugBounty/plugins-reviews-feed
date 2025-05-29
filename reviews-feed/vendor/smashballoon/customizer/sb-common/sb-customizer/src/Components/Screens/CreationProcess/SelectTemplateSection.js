import { useContext } from 'react'
import SbUtils from '../../../Utils/SbUtils'
import { __ } from '@wordpress/i18n'
import Template from '../../Common/Template';
import DashboardScreenContext from '../../Context/DashboardScreenContext'

const SelectTemplateSection = ( props ) => {

    const {
        templates,
        isPro,
        upsellModal
    } = useContext( DashboardScreenContext );


    return (
        <div className='sb-creationprocess-content sb-fs'>
            <div className='sb-templates-list sb-fs'>
                {
                    templates.map( ( template, templateInd ) => {
                        const isTemplatePro = SbUtils.checkSettingIsPro( template.upsellModal );
                        return(
                            <Template
                                key={ templateInd }
                                type={ template.type }
                                title={ template.title }
                                isChecked={ props.selectedTemplate === template.type}
                                isTemplatePro={ isTemplatePro }
                                onClick={ () => {
                                    if( isTemplatePro === false  ){
                                        props.selectTemplateAction( template.type )
                                    }else{
                                        SbUtils.openUpsellModal( template.upsellModal, upsellModal )
                                    }
                                } }
                            />
                        )
                    } )
                }
            </div>
        </div>
    )
}

export default SelectTemplateSection;