import { __ } from "@wordpress/i18n"
import { useState } from "react"
import Input from "../../../Common/Input"

const ManageTranslation = ( props ) => {

    const [ translationsObject, setTranslationsObject ] = useState( props.value );


    const updateText = ( event, element ) => {
            setTranslationsObject(  {
                ...translationsObject,
                [ element.id ] : event.currentTarget.value
            }  );

            props.onChangeTranslation( translationsObject );
        }

    return(
        <table className='sb-translation-table sb-default-table'>

            <thead>
                <tr>
                    <th>{ __( 'Original Text', 'sb-customizer' ) }</th>
                    <th>{ __( 'Custom text/translation', 'sb-customizer' ) }</th>
                    <th>{ __( 'Context', 'sb-customizer' ) }</th>
                </tr>
            </thead>
            {
                props?.translations?.sections &&
                props?.translations?.sections.map( ( section, seckey ) => {
                    return (
                        <tbody key={ seckey }>
                            <tr className='sb-default-table-row-header'>
                                <td colSpan='3'>{ section?.heading }</td>
                            </tr>
                            {
                                section?.elements &&
                                section?.elements.map( ( element, elkey ) => {
                                    return (
                                        <tr key={elkey}>
                                            <td>{ element?.text }</td>
                                            <td>
                                                <Input
                                                    type='text'
                                                    size='medium'
                                                    placeholder={ element?.text }
                                                    value={ translationsObject[element.id] }
                                                    onKeyUp={ ( event ) => {
                                                        updateText(event, element)
                                                    } }
                                                    onChange={ ( event ) => {
                                                        updateText(event, element)
                                                    } }
                                                />
                                            </td>
                                            <td>{ element?.description }</td>
                                        </tr>
                                     )
                                } )
                            }
                        </tbody>
                    )
                } )
            }

            <tfoot>
                <tr>
                    <th>{ __( 'Original Text', 'sb-customizer' ) }</th>
                    <th>{ __( 'Custom text/translation', 'sb-customizer' ) }</th>
                    <th>{ __( 'Context', 'sb-customizer' ) }</th>
                </tr>
            </tfoot>


        </table>
    )
}

export default ManageTranslation;